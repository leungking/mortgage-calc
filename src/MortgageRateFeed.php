<?php

namespace Hooey\MortgageCalc;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

class MortgageRateFeed extends AbstractModel
{
    protected $table = 'hooey_mortgage_rate_feed';

    public $timestamps = false;

    protected $fillable = ['rate_key', 'rate_value', 'effective_date', 'fetched_at', 'source'];

    public static function keys(): array
    {
        return [
            'lpr_5y' => 'LPR 5年期',
            'sz_commercial' => '深圳商贷',
            'gjj_5y' => '公积金5年以下',
            'gjj_over5y' => '公积金5年以上',
        ];
    }

    public static function defaultValues(): array
    {
        return [
            'lpr_5y' => 3.50,
            'sz_commercial' => 3.05,
            'gjj_5y' => 2.10,
            'gjj_over5y' => 2.60,
        ];
    }

    /** Seed the four reference rates on first read (idempotent). */
    public static function ensureDefaults(): void
    {
        if (static::query()->count() > 0) {
            return;
        }

        $today = Carbon::today()->toDateString();

        foreach (static::defaultValues() as $key => $value) {
            static::create([
                'rate_key' => $key,
                'rate_value' => $value,
                'effective_date' => $today,
                'fetched_at' => Carbon::now(),
                'source' => 'seed-default',
            ]);
        }
    }

    /** Latest value per key, like the old site's feedRates(). */
    public static function latest(): array
    {
        static::ensureDefaults();

        $out = [];

        foreach (static::keys() as $key => $label) {
            $row = static::where('rate_key', $key)->orderBy('effective_date', 'desc')->orderBy('id', 'desc')->first();
            $out[$key] = $row ? [
                'label' => $label,
                'rate' => (float) $row->rate_value,
                'effective_date' => substr((string) $row->effective_date, 0, 10),
            ] : ['label' => $label, 'rate' => null, 'effective_date' => null];
        }

        return $out;
    }

    public static function latestRate(string $key): ?float
    {
        $all = static::latest();

        return isset($all[$key]) && $all[$key]['rate'] !== null ? (float) $all[$key]['rate'] : null;
    }
}
