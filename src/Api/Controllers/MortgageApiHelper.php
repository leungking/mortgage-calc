<?php

namespace Hooey\MortgageCalc\Api\Controllers;

use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Hooey\MortgageCalc\MortgageConversion;
use Hooey\MortgageCalc\MortgageEarlyRepay;
use Hooey\MortgageCalc\MortgagePlan;
use Hooey\MortgageCalc\MortgageRateChange;
use Hooey\MortgageCalc\MortgageSchedule;
use Hooey\MortgageCalc\MortgageValidationException;
use Hooey\Residences\ResidenceMembership;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

trait MortgageApiHelper
{
    protected function actor(ServerRequestInterface $request): User
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            throw new PermissionDeniedException();
        }

        return $actor;
    }

    protected function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        if (is_array($parsed)) {
            return $parsed;
        }

        $raw = (string) $request->getBody();
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    protected function param(ServerRequestInterface $request, string $key): ?string
    {
        $v = Arr::get($request->getAttribute('routeParameters'), $key);

        return $v === null ? null : (string) $v;
    }

    protected function error(string $detail, int $code = 422): ResponseInterface
    {
        return new JsonResponse(['errors' => [['detail' => $detail]]], $code);
    }

    protected function validationError(MortgageValidationException $e): ResponseInterface
    {
        return $this->error($e->getMessage(), 422);
    }

    /** Own plan or null (unknown ids and other users' plans both read as missing). */
    protected function ownPlan(User $actor, $id): ?MortgagePlan
    {
        if ($id === null || (int) $id <= 0) {
            return null;
        }

        return MortgagePlan::where('id', (int) $id)->where('user_id', $actor->id)->first();
    }

    protected function hasMembership(int $userId, int $residenceId): bool
    {
        return ResidenceMembership::where('user_id', $userId)
            ->where('residence_id', $residenceId)
            ->exists();
    }

    protected function dateStr($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    protected function planArray(MortgagePlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'user_id' => (int) $plan->user_id,
            'residence_id' => $plan->residence_id !== null ? (int) $plan->residence_id : null,
            'name' => $plan->name,
            'loan_mode' => $plan->loan_mode,
            'total_price' => (float) $plan->total_price,
            'down_payment_ratio' => (float) $plan->down_payment_ratio,
            'loan_total' => (float) $plan->loan_total,
            'commercial_amount' => (float) $plan->commercial_amount,
            'commercial_rate' => (float) $plan->commercial_rate,
            'commercial_float' => (bool) $plan->commercial_float,
            'commercial_spread_bp' => (int) $plan->commercial_spread_bp,
            'gjj_amount' => (float) $plan->gjj_amount,
            'gjj_rate' => (float) $plan->gjj_rate,
            'gjj_float' => (bool) $plan->gjj_float,
            'term_months' => (int) $plan->term_months,
            'method' => $plan->method,
            'first_repayment_date' => $this->dateStr($plan->first_repayment_date),
        ];
    }

    /** Child rows as plain arrays with Y-m-d dates. */
    protected function children(MortgagePlan $plan): array
    {
        $early = MortgageEarlyRepay::where('plan_id', $plan->id)->orderBy('repay_date')->orderBy('id')->get()->map(function ($e) {
            return ['id' => (int) $e->id, 'repay_date' => $this->dateStr($e->repay_date), 'amount' => (float) $e->amount, 'mode' => $e->mode];
        })->all();
        $convs = MortgageConversion::where('plan_id', $plan->id)->orderBy('convert_date')->orderBy('id')->get()->map(function ($c) {
            return ['id' => (int) $c->id, 'convert_date' => $this->dateStr($c->convert_date), 'amount' => (float) $c->amount, 'gjj_rate' => (float) $c->gjj_rate];
        })->all();
        $rates = MortgageRateChange::where('plan_id', $plan->id)->orderBy('effective_date')->orderBy('id')->get()->map(function ($r) {
            return ['id' => (int) $r->id, 'part_key' => $r->part_key, 'effective_date' => $this->dateStr($r->effective_date), 'new_rate' => (float) $r->new_rate, 'is_auto' => (bool) $r->is_auto];
        })->all();

        return [$early, $convs, $rates];
    }

    protected function summaryFor(MortgagePlan $plan, bool $withSchedule = false): array
    {
        [$early, $convs, $rates] = $this->children($plan);
        $summary = MortgageSchedule::summary($this->planArray($plan), $early, $convs, $rates);

        if (!$withSchedule) {
            unset($summary['schedule']);
        }

        return $summary;
    }

    /** Future repayment dates (next 24) for early-repay / conversion pickers. */
    protected function futureDates(array $schedule, int $limit = 24): array
    {
        $today = date('Y-m-d');
        $out = [];
        foreach ($schedule['periods'] as $per) {
            if ($per['date'] && $per['date'] > $today && count($out) < $limit) {
                $out[] = $per['date'];
            }
        }
        return $out;
    }
}
