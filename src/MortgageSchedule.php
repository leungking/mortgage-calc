<?php

namespace Hooey\MortgageCalc;

/**
 * Housing-loan calculation ported from the old site's MortgageService.
 * Pure functions: the schedule engine takes plain arrays (plan fields,
 * early repayments, conversions, rate changes) so it can be verified
 * without a database. Rounding and ordering mirror the old site exactly:
 * per-period principal/interest rounded to 2 decimals, parts sorted by
 * rate descending (early repayment hits the higher-rate part first),
 * rate changes reprice from their effective repayment date with the
 * term unchanged, conversions move commercial principal into a new
 * fund part with the remaining term unchanged.
 */
final class MortgageSchedule
{
    const DEFAULT_COMMERCIAL_RATE = 3.05;
    const DEFAULT_LPR_5Y = 3.5;
    const DEFAULT_GJJ_RATE = 2.6;
    const DEFAULT_GJJ_RATE_5Y = 2.1;
    const MAX_TERM_MONTHS = 684;

    public static function loanModes(): array
    {
        return ['commercial' => '商业贷', 'gjj' => '公积金贷', 'combined' => '组合贷'];
    }

    public static function methods(): array
    {
        return ['equal_installment' => '等额本息', 'equal_principal' => '等额本金'];
    }

    public static function earlyRepayModes(): array
    {
        return ['reduce_payment' => '减少月供', 'shorten_term' => '缩短年限'];
    }

    public static function defaultRates($termMonths): array
    {
        return [
            'commercial' => self::DEFAULT_COMMERCIAL_RATE,
            'gjj' => ((int) $termMonths <= 60) ? self::DEFAULT_GJJ_RATE_5Y : self::DEFAULT_GJJ_RATE,
        ];
    }

    /** Monthly payment (equal installments: fixed; equal principal: first month). */
    public static function monthlyPayment($principal, $annualRate, $method, $n): float
    {
        $principal = (float) $principal;
        if ($n <= 0 || $principal <= 0) {
            return 0;
        }
        $r = (float) $annualRate / 100 / 12;
        if ($method === 'equal_principal') {
            return round($principal / $n + $principal * $r, 2);
        }
        if ($r <= 0) {
            return round($principal / $n, 2);
        }
        return round($principal * $r * pow(1 + $r, $n) / (pow(1 + $r, $n) - 1), 2);
    }

    /** Periods needed to clear a balance at a fixed payment (shorten-term mode). */
    public static function periodsFor($principal, $annualRate, $payment): int
    {
        $principal = (float) $principal;
        if ($principal <= 0.005) {
            return 0;
        }
        $r = (float) $annualRate / 100 / 12;
        if ($r <= 0) {
            return (int) ceil($principal / max(0.01, $payment));
        }
        if ($payment <= $principal * $r) {
            return 1200;
        }
        return (int) ceil(-log(1 - $r * $principal / $payment) / log(1 + $r) - 1e-9);
    }

    /** Add months, clamped to month end (Jan 31 + 1 month = Feb 28). */
    public static function addMonths($dateStr, $months): string
    {
        $d = new \DateTime($dateStr);
        $day = (int) $d->format('d');
        $d->modify('first day of this month');
        $d->modify('+' . (int) $months . ' months');
        $d->setDate((int) $d->format('Y'), (int) $d->format('m'), min($day, (int) $d->format('t')));
        return $d->format('Y-m-d');
    }

    public static function planParts(array $plan): array
    {
        $parts = [];
        if (in_array($plan['loan_mode'], ['commercial', 'combined'], true) && (float) $plan['commercial_amount'] > 0) {
            $parts[] = ['key' => 'commercial', 'label' => '商业贷', 'principal' => (float) $plan['commercial_amount'], 'rate' => (float) $plan['commercial_rate']];
        }
        if (in_array($plan['loan_mode'], ['gjj', 'combined'], true) && (float) $plan['gjj_amount'] > 0) {
            $parts[] = ['key' => 'gjj', 'label' => '公积金贷', 'principal' => (float) $plan['gjj_amount'], 'rate' => (float) $plan['gjj_rate']];
        }
        // Higher rate first (early repayment pays the commercial part first).
        usort($parts, function ($a, $b) { return $b['rate'] <=> $a['rate']; });
        return $parts;
    }

    /**
     * Full repayment schedule (with early repayments, conversions, rate changes).
     * Each period: n, date, principal, interest, payment, extra, remaining,
     * commercial_remaining, cum_principal, cum_interest, cum_total.
     */
    public static function buildSchedule(array $plan, array $early = [], array $convs = [], array $rateChanges = []): array
    {
        $method = $plan['method'];
        $n0 = max(1, min(self::MAX_TERM_MONTHS, (int) $plan['term_months']));
        $firstDate = !empty($plan['first_repayment_date']) ? substr((string) $plan['first_repayment_date'], 0, 10) : null;

        $earlyByDate = [];
        foreach ($early as $e) {
            $earlyByDate[substr((string) $e['repay_date'], 0, 10)][] = $e;
        }
        $convByDate = [];
        foreach ($convs as $c) {
            $convByDate[substr((string) $c['convert_date'], 0, 10)][] = $c;
        }
        $rateByDate = [];
        foreach ($rateChanges as $rc) {
            $rateByDate[substr((string) $rc['effective_date'], 0, 10)][$rc['part_key']] = (float) $rc['new_rate'];
        }

        $parts = [];
        foreach (self::planParts($plan) as $pt) {
            $parts[] = [
                'key' => $pt['key'],
                'rate' => $pt['rate'],
                'remaining' => round($pt['principal'], 2),
                'periods_left' => $n0,
                'payment' => self::monthlyPayment($pt['principal'], $pt['rate'], $method, $n0),
                'base' => $pt['principal'] / $n0,
            ];
        }

        $empty = ['periods' => [], 'total_principal' => 0, 'total_interest' => 0, 'total_payment' => 0, 'end_date' => null, 'count' => 0];
        if (!$parts) {
            return $empty;
        }

        $periods = [];
        $cumP = 0; $cumI = 0; $cumT = 0;
        $k = 0;
        $maxIter = $n0 + 1200;
        while ($k < $maxIter) {
            $active = false;
            foreach ($parts as $s) {
                if ($s['remaining'] > 0.005 && $s['periods_left'] > 0) {
                    $active = true;
                    break;
                }
            }
            if (!$active) {
                break;
            }
            $k++;
            $date = $firstDate ? self::addMonths($firstDate, $k - 1) : null;
            $row = ['n' => $k, 'date' => $date, 'principal' => 0, 'interest' => 0, 'payment' => 0, 'extra' => 0];
            // Repricing at this date: change rate first, then recompute
            // the payment; the term stays unchanged. Equal principal only
            // changes interest (its base slice is fixed).
            if ($date && isset($rateByDate[$date])) {
                foreach ($parts as &$s) {
                    if (!isset($rateByDate[$date][$s['key']])) {
                        continue;
                    }
                    $s['rate'] = $rateByDate[$date][$s['key']];
                    if ($method === 'equal_installment') {
                        $s['payment'] = self::monthlyPayment($s['remaining'], $s['rate'], $method, $s['periods_left']);
                    }
                }
                unset($s);
                $row['rate_changed'] = true;
            }
            foreach ($parts as &$s) {
                if ($s['remaining'] <= 0.005 || $s['periods_left'] <= 0) {
                    continue;
                }
                $r = $s['rate'] / 100 / 12;
                $interest = round($s['remaining'] * $r, 2);
                if ($method === 'equal_installment') {
                    $princ = ($s['periods_left'] <= 1) ? $s['remaining'] : min(round($s['payment'] - $interest, 2), $s['remaining']);
                } else {
                    $princ = ($s['periods_left'] <= 1) ? $s['remaining'] : min(round($s['base'], 2), $s['remaining']);
                }
                $princ = round(max(0, $princ), 2);
                $row['principal'] += $princ;
                $row['interest'] += $interest;
                $row['payment'] += $princ + $interest;
                $s['remaining'] = round($s['remaining'] - $princ, 2);
                $s['periods_left']--;
            }
            unset($s);
            // Early repayment this period (after the normal payment).
            if ($date && isset($earlyByDate[$date])) {
                foreach ($earlyByDate[$date] as $e) {
                    $extra = round((float) $e['amount'], 2);
                    foreach ($parts as &$s) {
                        if ($extra <= 0) {
                            break;
                        }
                        if ($s['remaining'] <= 0.005) {
                            continue;
                        }
                        $take = round(min($extra, $s['remaining']), 2);
                        $s['remaining'] = round($s['remaining'] - $take, 2);
                        $extra = round($extra - $take, 2);
                        $row['extra'] = round($row['extra'] + $take, 2);
                        if ($e['mode'] === 'reduce_payment') {
                            if ($method === 'equal_installment') {
                                $s['payment'] = self::monthlyPayment($s['remaining'], $s['rate'], $method, $s['periods_left']);
                            } else {
                                $s['base'] = $s['periods_left'] > 0 ? $s['remaining'] / $s['periods_left'] : 0;
                            }
                        } else {
                            if ($method === 'equal_installment') {
                                $s['periods_left'] = self::periodsFor($s['remaining'], $s['rate'], $s['payment']);
                            } else {
                                $s['periods_left'] = $s['base'] > 0 ? (int) ceil($s['remaining'] / $s['base'] - 1e-9) : 0;
                            }
                        }
                    }
                    unset($s);
                }
            }
            // Conversion this period (after normal + early repayment):
            // move commercial principal into a new fund part, term unchanged.
            if ($date && isset($convByDate[$date])) {
                foreach ($convByDate[$date] as $c) {
                    $amt = round((float) $c['amount'], 2);
                    $gRate = (float) $c['gjj_rate'];
                    $converted = 0; $convPeriods = 0;
                    foreach ($parts as &$s) {
                        if ($amt <= 0) {
                            break;
                        }
                        if ($s['key'] !== 'commercial' || $s['remaining'] <= 0.005) {
                            continue;
                        }
                        $take = round(min($amt, $s['remaining']), 2);
                        $s['remaining'] = round($s['remaining'] - $take, 2);
                        $amt = round($amt - $take, 2);
                        $converted = round($converted + $take, 2);
                        $convPeriods = $s['periods_left'];
                        if ($method === 'equal_installment') {
                            $s['payment'] = self::monthlyPayment($s['remaining'], $s['rate'], $method, $s['periods_left']);
                        } else {
                            $s['base'] = $s['periods_left'] > 0 ? $s['remaining'] / $s['periods_left'] : 0;
                        }
                    }
                    unset($s);
                    if ($converted > 0 && $convPeriods > 0) {
                        $parts[] = [
                            'key' => 'gjj',
                            'rate' => $gRate,
                            'remaining' => $converted,
                            'periods_left' => $convPeriods,
                            'payment' => self::monthlyPayment($converted, $gRate, $method, $convPeriods),
                            'base' => $converted / $convPeriods,
                        ];
                    }
                }
            }
            $row['principal'] = round($row['principal'], 2);
            $row['interest'] = round($row['interest'], 2);
            $row['payment'] = round($row['payment'], 2);
            $cumP += $row['principal']; $cumI += $row['interest']; $cumT += $row['payment'];
            $rem = 0; $commRem = 0;
            foreach ($parts as $s) {
                $rem += $s['remaining'];
                if ($s['key'] === 'commercial') {
                    $commRem += $s['remaining'];
                }
            }
            $row['remaining'] = round(max(0, $rem), 2);
            $row['commercial_remaining'] = round(max(0, $commRem), 2);
            $row['cum_principal'] = round($cumP, 2);
            $row['cum_interest'] = round($cumI, 2);
            $row['cum_total'] = round($cumT, 2);
            $periods[] = $row;
        }
        $totalP = round($cumP, 2); $totalI = round($cumI, 2);
        return [
            'periods' => $periods,
            'total_principal' => $totalP,
            'total_interest' => $totalI,
            'total_payment' => round($totalP + $totalI, 2),
            'end_date' => $periods ? $periods[count($periods) - 1]['date'] : null,
            'count' => count($periods),
        ];
    }

    /** Summary for list headers and detail pages (mirrors the old summary()). */
    public static function summary(array $plan, array $early = [], array $convs = [], array $rateChanges = []): array
    {
        $sched = self::buildSchedule($plan, $early, $convs, $rateChanges);
        $first = $sched['periods'][0] ?? null;
        $last = $sched['periods'] ? $sched['periods'][count($sched['periods']) - 1] : null;
        $today = date('Y-m-d');
        $next = null;
        foreach ($sched['periods'] as $per) {
            if ($per['date'] && $per['date'] >= $today && $per['payment'] > 0) {
                $next = $per;
                break;
            }
        }
        $decline = 0;
        if ($plan['method'] === 'equal_principal' && count($sched['periods']) >= 2) {
            $decline = round($sched['periods'][0]['payment'] - $sched['periods'][1]['payment'], 2);
        }
        return [
            'schedule' => $sched,
            'first_payment' => $first ? $first['payment'] : 0,
            'last_payment' => $last ? $last['payment'] : 0,
            'monthly_decline' => $decline,
            'total_interest' => $sched['total_interest'],
            'total_payment' => $sched['total_payment'],
            'end_date' => $sched['end_date'],
            'count' => $sched['count'],
            'next' => $next,
        ];
    }

    /** Original schedule without early repayments, conversions or rate changes (savings comparison). */
    public static function baseSchedule(array $plan): array
    {
        return self::buildSchedule($plan, [], [], []);
    }

    /** Snap a date to the next repayment date on or after it (null when none). */
    public static function snapToRepaymentDate(array $plan, string $date): ?string
    {
        if (empty($plan['first_repayment_date'])) {
            return null;
        }
        $first = substr((string) $plan['first_repayment_date'], 0, 10);
        if ($date <= $first) {
            return $first;
        }
        $k = 0;
        while ($k < 1200) {
            $d = self::addMonths($first, $k);
            if ($d >= $date) {
                return $d;
            }
            $k++;
        }
        return null;
    }

    /** Rate actually in force for a part on a date (latest change wins, else plan rate). */
    public static function effectiveRateAt(array $plan, array $rateChanges, string $partKey, string $date): float
    {
        $best = null;
        foreach ($rateChanges as $rc) {
            if ($rc['part_key'] === $partKey && substr((string) $rc['effective_date'], 0, 10) <= $date) {
                if ($best === null || substr((string) $rc['effective_date'], 0, 10) >= substr((string) $best['effective_date'], 0, 10)) {
                    $best = $rc;
                }
            }
        }
        if ($best) {
            return (float) $best['new_rate'];
        }
        return $partKey === 'commercial' ? (float) $plan['commercial_rate'] : (float) $plan['gjj_rate'];
    }

    /** Validate + normalize plan input (mirrors the old normalize()). */
    public static function normalize(array $data, ?float $feedLpr = null): array
    {
        $modes = array_keys(self::loanModes());
        $methods = array_keys(self::methods());
        $loanMode = (string) ($data['loan_mode'] ?? 'commercial');
        if (!in_array($loanMode, $modes, true)) {
            throw new MortgageValidationException('请选择贷款模式。');
        }
        $method = (string) ($data['method'] ?? 'equal_installment');
        if (!in_array($method, $methods, true)) {
            throw new MortgageValidationException('请选择还款方式。');
        }
        $term = max(1, min(self::MAX_TERM_MONTHS, (int) ($data['term_months'] ?? 360)));
        $totalPrice = max(0, round((float) ($data['total_price'] ?? 0), 2));
        $loanTotal = round((float) ($data['loan_total'] ?? 0), 2);
        if ($loanTotal <= 0) {
            throw new MortgageValidationException('贷款总额须大于 0。');
        }
        if ($totalPrice > 0 && $loanTotal > $totalPrice) {
            throw new MortgageValidationException('贷款总额不能超过购房总价。');
        }
        $downRatio = $totalPrice > 0 ? round(($totalPrice - $loanTotal) / $totalPrice * 100, 2) : 0;
        $cRate = max(0, min(20, round((float) ($data['commercial_rate'] ?? self::DEFAULT_COMMERCIAL_RATE), 3)));
        $gRate = max(0, min(20, round((float) ($data['gjj_rate'] ?? ($term <= 60 ? self::DEFAULT_GJJ_RATE_5Y : self::DEFAULT_GJJ_RATE)), 3)));
        $cFloat = !empty($data['commercial_float']) ? 1 : 0;
        $gFloat = !empty($data['gjj_float']) ? 1 : 0;
        $cSpread = 0;
        if ($cFloat) {
            $lprVal = $feedLpr !== null ? $feedLpr : self::DEFAULT_LPR_5Y;
            $cSpread = (int) round(($cRate - $lprVal) * 100);
        }
        $cAmt = round((float) ($data['commercial_amount'] ?? 0), 2);
        $gAmt = round((float) ($data['gjj_amount'] ?? 0), 2);
        if ($loanMode === 'commercial') {
            $cAmt = $loanTotal; $gAmt = 0;
        } elseif ($loanMode === 'gjj') {
            $gAmt = $loanTotal; $cAmt = 0;
        } else {
            if ($gAmt <= 0) {
                $gAmt = min(700000, $loanTotal);
            }
            $cAmt = round($loanTotal - $gAmt, 2);
        }
        if ($cAmt < 0 || $gAmt < 0) {
            throw new MortgageValidationException('贷款金额分配有误。');
        }
        if (abs($cAmt + $gAmt - $loanTotal) > 1) {
            throw new MortgageValidationException('商贷与公积金贷款额之和须等于贷款总额。');
        }
        $firstDate = trim((string) ($data['first_repayment_date'] ?? ''));
        if ($firstDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstDate)) {
            throw new MortgageValidationException('首次还款日格式有误。');
        }
        if ($firstDate !== '') {
            [$y, $m, $d] = array_map('intval', explode('-', $firstDate));
            if (!checkdate($m, $d, $y)) {
                throw new MortgageValidationException('首次还款日不是有效日期。');
            }
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = '房贷方案';
        }
        return [
            'name' => mb_substr($name, 0, 80, 'UTF-8'),
            'residence_id' => (int) ($data['residence_id'] ?? 0) ?: null,
            'loan_mode' => $loanMode,
            'total_price' => $totalPrice,
            'down_payment_ratio' => $downRatio,
            'loan_total' => $loanTotal,
            'commercial_amount' => $cAmt,
            'commercial_rate' => $cRate,
            'gjj_amount' => $gAmt,
            'gjj_rate' => $gRate,
            'commercial_float' => $cFloat,
            'commercial_spread_bp' => $cSpread,
            'gjj_float' => $gFloat,
            'term_months' => $term,
            'method' => $method,
            'first_repayment_date' => $firstDate !== '' ? $firstDate : null,
        ];
    }

    /** Validate an early-repayment trial (mirrors the old addEarlyRepay checks). */
    public static function validateEarlyRepay(array $plan, array $schedule, string $date, float $amount, string $mode): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new MortgageValidationException('请选择提前还款日。');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new MortgageValidationException('提前还款金额须大于 0。');
        }
        if (!isset(self::earlyRepayModes()[$mode])) {
            throw new MortgageValidationException('请选择提前还款方式。');
        }
        if (empty($plan['first_repayment_date'])) {
            throw new MortgageValidationException('请先设置首次还款日。');
        }
        $today = date('Y-m-d');
        $remaining = null;
        foreach ($schedule['periods'] as $per) {
            if ($per['date'] === $date && $date > $today) {
                $remaining = $per['remaining'];
                break;
            }
        }
        if ($remaining === null) {
            throw new MortgageValidationException('提前还款日须为未来的还款日。');
        }
        if ($amount > $remaining) {
            throw new MortgageValidationException('提前还款金额不能超过剩余本金（¥' . number_format($remaining, 2) . '）。');
        }
    }

    /** Validate a conversion trial; returns the max convertible amount. */
    public static function validateConversion(array $plan, array $schedule, string $date, float $amount): float
    {
        if (!in_array($plan['loan_mode'], ['commercial', 'combined'], true)) {
            throw new MortgageValidationException('只有含商业贷的方案才能商转公。');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new MortgageValidationException('请选择转贷日期。');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new MortgageValidationException('转贷金额须大于 0。');
        }
        if (empty($plan['first_repayment_date'])) {
            throw new MortgageValidationException('请先设置首次还款日。');
        }
        $today = date('Y-m-d');
        $maxAmt = null;
        foreach ($schedule['periods'] as $per) {
            if ($per['date'] === $date && $date > $today) {
                $maxAmt = $per['commercial_remaining'];
                break;
            }
        }
        if ($maxAmt === null) {
            throw new MortgageValidationException('转贷日期须为未来的还款日。');
        }
        if ($amount > $maxAmt) {
            throw new MortgageValidationException('转贷金额不能超过该日商贷剩余本金（¥' . number_format($maxAmt, 2) . '）。');
        }
        return $maxAmt;
    }

    /** Validate a rate change; returns the snapped effective repayment date. */
    public static function validateRateChange(array $plan, string $partKey, string $date, float $rate): string
    {
        if (!in_array($partKey, ['commercial', 'gjj'], true)) {
            throw new MortgageValidationException('参数有误。');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new MortgageValidationException('请选择生效日期。');
        }
        $rate = round($rate, 3);
        if ($rate < 0 || $rate > 20) {
            throw new MortgageValidationException('利率超出范围。');
        }
        $snap = self::snapToRepaymentDate($plan, $date);
        if (!$snap) {
            throw new MortgageValidationException('该日期之后没有还款日。');
        }
        return $snap;
    }
}
