<?php

namespace Hooey\MortgageCalc;

use Flarum\Database\AbstractModel;

class MortgageRateChange extends AbstractModel
{
    protected $table = 'hooey_mortgage_rate_changes';

    public $timestamps = false;

    protected $fillable = ['plan_id', 'part_key', 'effective_date', 'new_rate', 'is_auto', 'created_at'];

    public function plan()
    {
        return $this->belongsTo(MortgagePlan::class, 'plan_id');
    }
}
