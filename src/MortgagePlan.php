<?php

namespace Hooey\MortgageCalc;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

class MortgagePlan extends AbstractModel
{
    protected $table = 'hooey_mortgage_plans';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'residence_id', 'name', 'loan_mode', 'total_price',
        'down_payment_ratio', 'loan_total', 'commercial_amount',
        'commercial_rate', 'commercial_float', 'commercial_spread_bp',
        'gjj_amount', 'gjj_rate', 'gjj_float', 'term_months', 'method',
        'first_repayment_date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function earlyRepays()
    {
        return $this->hasMany(MortgageEarlyRepay::class, 'plan_id')->orderBy('repay_date')->orderBy('id');
    }

    public function conversions()
    {
        return $this->hasMany(MortgageConversion::class, 'plan_id')->orderBy('convert_date')->orderBy('id');
    }

    public function rateChanges()
    {
        return $this->hasMany(MortgageRateChange::class, 'plan_id')->orderBy('effective_date')->orderBy('id');
    }
}
