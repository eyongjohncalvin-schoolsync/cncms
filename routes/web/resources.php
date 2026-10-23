<?php

declare(strict_types=1);

use App\Http\Controllers\BudgetController;
use App\Http\Controllers\ResourceController;
use Illuminate\Support\Facades\Route;

Route::get('resources', [ResourceController::class, 'dashboard'])->name('resources.dashboard');

// Expenditures (docs/plans/expenditures-and-spending-controls.md): no
// create route — every cash-out is a bon (E1). Month view, detail with
// history, edits/voids with a reason, the owner's month close/reopen,
// budgets. Writes are refused while a landlord impersonates (E9).
Route::get('resources/expenditures', [ResourceController::class, 'expenditures'])->name('resources.expenditures.index');
Route::get('resources/expenditures/export', [ResourceController::class, 'exportExpenditures'])->name('resources.expenditures.export');
Route::get('resources/expenditures/{expenditure}', [ResourceController::class, 'showExpenditure'])->whereUuid('expenditure')->name('resources.expenditures.show');
Route::get('resources/budgets', [BudgetController::class, 'index'])->name('resources.budgets.index');

Route::middleware('not-impersonating:money')->group(function () {
    Route::patch('resources/expenditures/{expenditure}', [ResourceController::class, 'updateExpenditure'])->whereUuid('expenditure')->name('resources.expenditures.update');
    Route::post('resources/expenditures/{expenditure}/void', [ResourceController::class, 'voidExpenditure'])->whereUuid('expenditure')->name('resources.expenditures.void');
    Route::post('resources/expenditure-months/{period}/close', [ResourceController::class, 'closeMonth'])->where('period', '\d{4}-\d{2}')->name('resources.expenditure-months.close');
    Route::post('resources/expenditure-months/{period}/reopen', [ResourceController::class, 'reopenMonth'])->where('period', '\d{4}-\d{2}')->name('resources.expenditure-months.reopen');
    Route::put('resources/budgets', [BudgetController::class, 'update'])->name('resources.budgets.update');
    Route::post('resources/budgets/copy', [BudgetController::class, 'copyFromPreviousMonth'])->name('resources.budgets.copy');
});

Route::get('resources/categories', [ResourceController::class, 'categories'])->name('resources.categories.index');
Route::post('resources/categories', [ResourceController::class, 'storeCategory'])->name('resources.categories.store');
Route::patch('resources/categories/{category}', [ResourceController::class, 'updateCategory'])->name('resources.categories.update');
Route::delete('resources/categories/{category}', [ResourceController::class, 'destroyCategory'])->name('resources.categories.destroy');
