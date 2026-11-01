<?php

namespace Hooey\MortgageCalc;

use Flarum\Database\AbstractModel;

class MortgageConversion extends AbstractModel
{
    protected $table = 'hooey_mortgage_conversions';

    public $timestamps = false;

    protected $fillable = ['plan_id', 'convert_date', 'amount', 'gjj_rate', 'created_at'];

    public function plan()
    {
        return $this->belongsTo(MortgagePlan::class, 'plan_id');
    }
}
