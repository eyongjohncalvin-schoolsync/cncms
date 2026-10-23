<?php

declare(strict_types=1);

use App\Http\Controllers\CashAlertController;
use App\Http\Controllers\CashBookController;
use App\Http\Controllers\RemittanceController;
use App\Http\Controllers\TechnicianFeeController;
use Illuminate\Support\Facades\Route;

// Cash book + the daily income form (docs/plans/cash-controls.md, wave 4) —
// CashAccountPolicy / RemittancePolicy.
Route::get('cash', [CashBookController::class, 'index'])->name('cash.index');
Route::get('cash/alerts', [CashAlertController::class, 'index'])->name('cash.alerts');
Route::get('cash/accounts/{cashAccount}', [CashBookController::class, 'show'])->name('cash.accounts.show');
Route::get('cash/technician-fees', [TechnicianFeeController::class, 'index'])->name('cash.technician-fees');
Route::get('cash/hand-over', [RemittanceController::class, 'create'])->name('cash.hand-over');
Route::get('cash/remittances/{remittance}', [RemittanceController::class, 'show'])->name('cash.remittances.show');

// Every write moves or accounts for money — refused while a landlord
// impersonates (E9, docs/plans/expenditures-and-spending-controls.md).
Route::middleware('not-impersonating:money')->group(function () {
    Route::post('cash/accounts', [CashBookController::class, 'storeAccount'])->name('cash.accounts.store');
    Route::post('cash/accounts/{cashAccount}/opening-balance', [CashBookController::class, 'openingBalance'])->name('cash.accounts.opening-balance');
    Route::post('cash/accounts/{cashAccount}/close-day', [CashBookController::class, 'closeDay'])->name('cash.accounts.close-day');
    Route::post('cash/accounts/{cashAccount}/reopen-day', [CashBookController::class, 'reopenDay'])->name('cash.accounts.reopen-day');
    Route::post('cash/transfers', [CashBookController::class, 'transfer'])->name('cash.transfers.store');

    // Technician motivation fees (docs/plans/installation-and-promotions.md,
    // D13): collected reconnection fines the cashier pays out to the technician.
    Route::post('cash/technician-fees/payouts', [TechnicianFeeController::class, 'store'])->name('cash.technician-fees.payouts.store');

    Route::post('cash/remittances', [RemittanceController::class, 'store'])->name('cash.remittances.store');
    Route::post('cash/remittances/{remittance}/receive', [RemittanceController::class, 'receive'])->name('cash.remittances.receive');
    Route::post('cash/remittances/{remittance}/shortage', [RemittanceController::class, 'settleShortage'])->name('cash.remittances.shortage');
});
