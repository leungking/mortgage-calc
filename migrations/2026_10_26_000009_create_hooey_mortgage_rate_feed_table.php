<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('hooey_mortgage_rate_feed', function (Blueprint $table) {
    $table->increments('id');
    $table->string('rate_key', 40);
    $table->decimal('rate_value', 5, 3)->default(0);
    $table->date('effective_date');
    $table->timestamp('fetched_at')->nullable();
    $table->string('source', 200)->default('');
    $table->unique(['rate_key', 'effective_date']);
});
