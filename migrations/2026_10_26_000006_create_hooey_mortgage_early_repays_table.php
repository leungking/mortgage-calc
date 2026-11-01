<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('hooey_mortgage_early_repays', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('plan_id')->unsigned();
    $table->date('repay_date');
    $table->decimal('amount', 12, 2)->default(0);
    $table->string('mode', 20)->default('reduce_payment');
    $table->timestamp('created_at')->nullable();
    $table->index(['plan_id', 'repay_date']);
    $table->foreign('plan_id')->references('id')->on('hooey_mortgage_plans')->onDelete('cascade');
});
