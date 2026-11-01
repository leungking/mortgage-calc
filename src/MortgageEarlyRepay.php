<?php

namespace Hooey\MortgageCalc;

use Flarum\Database\AbstractModel;

class MortgageEarlyRepay extends AbstractModel
{
    protected $table = 'hooey_mortgage_early_repays';

    public $timestamps = false;

    protected $fillable = ['plan_id', 'repay_date', 'amount', 'mode', 'created_at'];

    public function plan()
    {
        return $this->belongsTo(MortgagePlan::class, 'plan_id');
    }
}
