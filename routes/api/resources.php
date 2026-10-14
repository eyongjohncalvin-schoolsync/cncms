<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ExpenditureController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ResourcesDashboardController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Named with an 'api.' prefix so these don't collide with the web panel's
// identically-shaped route names — see routes/web/resources.php and the
// same convention in routes/api/payments.php.
Route::prefix('resources')->group(function () {
    Route::get('dashboard', [ResourcesDashboardController::class, 'index']);

    // Approvals inbox — registered before the apiResource so the literal
    // segment is never read as an expenditure uuid.
    Route::get('approvals', [ExpenditureController::class, 'approvals'])->name('api.resources.approvals');
    Route::get('expense-limits', [ExpenditureController::class, 'limits'])->name('api.resources.expense-limits');

    Route::apiResource('expenditures', ExpenditureController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy'])
        ->names('api.resources.expenditures');

    Route::post('expenditures/{expenditure}/receipt', [ExpenditureController::class, 'attachReceipt'])->name('api.resources.expenditures.attach-receipt');
    Route::get('expenditures/{expenditure}/receipt', [ExpenditureController::class, 'receipt'])->name('api.resources.expenditures.receipt');
    Route::post('expenditures/{expenditure}/approve', [ExpenditureController::class, 'approve'])->name('api.resources.expenditures.approve');
    Route::post('expenditures/{expenditure}/reject', [ExpenditureController::class, 'reject'])->name('api.resources.expenditures.reject');
    Route::post('expenditures/{expenditure}/void', [ExpenditureController::class, 'void'])->name('api.resources.expenditures.void');

    Route::get('suppliers', [SupplierController::class, 'index'])->name('api.resources.suppliers.index');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('api.resources.suppliers.store');

    Route::apiResource('categories', ExpenseCategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('api.resources.categories');
});
