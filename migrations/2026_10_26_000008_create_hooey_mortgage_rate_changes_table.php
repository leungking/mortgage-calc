<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('hooey_mortgage_rate_changes', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('plan_id')->unsigned();
    $table->string('part_key', 20)->default('commercial');
    $table->date('effective_date');
    $table->decimal('new_rate', 5, 3)->default(0);
    $table->boolean('is_auto')->default(false);
    $table->timestamp('created_at')->nullable();
    $table->index(['plan_id', 'effective_date']);
    $table->foreign('plan_id')->references('id')->on('hooey_mortgage_plans')->onDelete('cascade');
});
