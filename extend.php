<?php

use Flarum\Extend;
use Hooey\MortgageCalc\Api\Controllers\MortgageConversionController;
use Hooey\MortgageCalc\Api\Controllers\MortgageEarlyRepayController;
use Hooey\MortgageCalc\Api\Controllers\MortgagePlanController;
use Hooey\MortgageCalc\Api\Controllers\MortgageRateChangeController;
use Hooey\MortgageCalc\Api\Controllers\MortgageRateFeedController;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->route('/mortgage', 'hooey.mortgage'),

    (new Extend\Routes('api'))
        ->get('/hooey/mortgages', 'hooey.mortgages.index', MortgagePlanController::class)
        ->post('/hooey/mortgages', 'hooey.mortgages.store', MortgagePlanController::class)
        ->get('/hooey/mortgages/{id}', 'hooey.mortgages.show', MortgagePlanController::class)
        ->patch('/hooey/mortgages/{id}', 'hooey.mortgages.update', MortgagePlanController::class)
        ->delete('/hooey/mortgages/{id}', 'hooey.mortgages.destroy', MortgagePlanController::class)
        ->post('/hooey/mortgages/{id}/early-repays', 'hooey.mortgages.early-repays.store', MortgageEarlyRepayController::class)
        ->delete('/hooey/mortgages/{id}/early-repays/{eid}', 'hooey.mortgages.early-repays.destroy', MortgageEarlyRepayController::class)
        ->post('/hooey/mortgages/{id}/conversions', 'hooey.mortgages.conversions.store', MortgageConversionController::class)
        ->delete('/hooey/mortgages/{id}/conversions/{cid}', 'hooey.mortgages.conversions.destroy', MortgageConversionController::class)
        ->post('/hooey/mortgages/{id}/rate-changes', 'hooey.mortgages.rate-changes.store', MortgageRateChangeController::class)
        ->delete('/hooey/mortgages/{id}/rate-changes/{rid}', 'hooey.mortgages.rate-changes.destroy', MortgageRateChangeController::class)
        ->get('/hooey/mortgage-rates', 'hooey.mortgage-rates.index', MortgageRateFeedController::class),
    // Migrations in this extension's migrations/ directory are picked up
    // automatically by Flarum 2.x (Extension::hasMigrations); no extender
    // exists for them.
];
