<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ExpenditureController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ResourcesDashboardController;
use Illuminate\Support\Facades\Route;

// Named with an 'api.' prefix so these don't collide with the web panel's
// identically-shaped route names — see routes/web/resources.php and the
// same convention in routes/api/payments.php.
Route::prefix('resources')->group(function () {
    Route::get('dashboard', [ResourcesDashboardController::class, 'index']);

    // No store / destroy (docs/plans/expenditures-and-spending-controls.md):
    // every cash-out is a bon, and a legacy row is voided, never deleted.
    Route::apiResource('expenditures', ExpenditureController::class)
        ->only(['index', 'update'])
        ->names('api.resources.expenditures');
    Route::post('expenditures/{expenditure}/void', [ExpenditureController::class, 'void'])->name('api.resources.expenditures.void');

    Route::apiResource('categories', ExpenseCategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('api.resources.categories');
});
