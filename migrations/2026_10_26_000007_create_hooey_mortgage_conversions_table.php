<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('hooey_mortgage_conversions', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('plan_id')->unsigned();
    $table->date('convert_date');
    $table->decimal('amount', 12, 2)->default(0);
    $table->decimal('gjj_rate', 5, 3)->default(0);
    $table->timestamp('created_at')->nullable();
    $table->index(['plan_id', 'convert_date']);
    $table->foreign('plan_id')->references('id')->on('hooey_mortgage_plans')->onDelete('cascade');
});
