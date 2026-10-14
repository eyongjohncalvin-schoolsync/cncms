<?php

declare(strict_types=1);

use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ResourceSettingsController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::get('resources', [ResourceController::class, 'dashboard'])->name('resources.dashboard');

Route::get('resources/approvals', [ResourceController::class, 'approvals'])->name('resources.approvals');

Route::get('resources/expenditures', [ResourceController::class, 'expenditures'])->name('resources.expenditures.index');
Route::get('resources/expenditures/export', [ResourceController::class, 'exportExpenditures'])->name('resources.expenditures.export')->middleware('throttle:exports');
Route::get('resources/expenditures/create', [ResourceController::class, 'createExpenditure'])->name('resources.expenditures.create');
Route::post('resources/expenditures', [ResourceController::class, 'storeExpenditure'])->name('resources.expenditures.store');
Route::get('resources/expenditures/{expenditure}', [ResourceController::class, 'showExpenditure'])->name('resources.expenditures.show');
Route::patch('resources/expenditures/{expenditure}', [ResourceController::class, 'updateExpenditure'])->name('resources.expenditures.update');
Route::post('resources/expenditures/{expenditure}/receipt', [ResourceController::class, 'attachReceipt'])->name('resources.expenditures.attach-receipt');
Route::get('resources/expenditures/{expenditure}/receipt', [ResourceController::class, 'receipt'])->name('resources.expenditures.receipt');
Route::post('resources/expenditures/{expenditure}/approve', [ResourceController::class, 'approveExpenditure'])->name('resources.expenditures.approve');
Route::post('resources/expenditures/{expenditure}/reject', [ResourceController::class, 'rejectExpenditure'])->name('resources.expenditures.reject');
Route::post('resources/expenditures/{expenditure}/void', [ResourceController::class, 'voidExpenditure'])->name('resources.expenditures.void');

Route::get('resources/suppliers', [SupplierController::class, 'index'])->name('resources.suppliers.index');
Route::post('resources/suppliers', [SupplierController::class, 'store'])->name('resources.suppliers.store');
Route::patch('resources/suppliers/{supplier}', [SupplierController::class, 'update'])->name('resources.suppliers.update');
Route::post('resources/suppliers/{supplier}/approve', [SupplierController::class, 'approve'])->name('resources.suppliers.approve');
Route::post('resources/suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate'])->name('resources.suppliers.deactivate');

Route::get('resources/settings', [ResourceSettingsController::class, 'edit'])->name('resources.settings');
Route::patch('resources/settings/limits', [ResourceSettingsController::class, 'updateLimits'])->name('resources.settings.limits');
Route::post('resources/settings/budgets', [ResourceSettingsController::class, 'saveBudgets'])->name('resources.settings.budgets');
Route::post('resources/settings/budgets/copy', [ResourceSettingsController::class, 'copyBudgets'])->name('resources.settings.budgets.copy');
Route::post('resources/recurring', [ResourceSettingsController::class, 'storeRecurring'])->name('resources.recurring.store');
Route::patch('resources/recurring/{recurringExpense}', [ResourceSettingsController::class, 'updateRecurring'])->name('resources.recurring.update');
Route::post('resources/recurring/{recurringExpense}/record', [ResourceSettingsController::class, 'recordRecurring'])->name('resources.recurring.record');

Route::get('resources/categories', [ResourceController::class, 'categories'])->name('resources.categories.index');
Route::post('resources/categories', [ResourceController::class, 'storeCategory'])->name('resources.categories.store');
Route::patch('resources/categories/{category}', [ResourceController::class, 'updateCategory'])->name('resources.categories.update');
Route::delete('resources/categories/{category}', [ResourceController::class, 'destroyCategory'])->name('resources.categories.destroy');
