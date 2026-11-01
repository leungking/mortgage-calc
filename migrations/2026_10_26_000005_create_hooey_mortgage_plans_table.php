<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('hooey_mortgage_plans', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('user_id')->unsigned();
    $table->integer('residence_id')->unsigned()->nullable();
    $table->string('name', 80)->default('');
    $table->string('loan_mode', 20)->default('commercial');
    $table->decimal('total_price', 12, 2)->default(0);
    $table->decimal('down_payment_ratio', 5, 2)->default(0);
    $table->decimal('loan_total', 12, 2)->default(0);
    $table->decimal('commercial_amount', 12, 2)->default(0);
    $table->decimal('commercial_rate', 5, 3)->default(0);
    $table->boolean('commercial_float')->default(false);
    $table->integer('commercial_spread_bp')->default(0);
    $table->decimal('gjj_amount', 12, 2)->default(0);
    $table->decimal('gjj_rate', 5, 3)->default(0);
    $table->boolean('gjj_float')->default(false);
    $table->integer('term_months')->unsigned()->default(360);
    $table->string('method', 20)->default('equal_installment');
    $table->date('first_repayment_date')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'id']);
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
});
