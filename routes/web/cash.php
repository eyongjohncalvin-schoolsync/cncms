<?php

declare(strict_types=1);

use App\Http\Controllers\CashAlertController;
use App\Http\Controllers\CashBookController;
use App\Http\Controllers\RemittanceController;
use Illuminate\Support\Facades\Route;

// Cash book + the daily income form (docs/plans/cash-controls.md, wave 4) —
// CashAccountPolicy / RemittancePolicy.
Route::get('cash', [CashBookController::class, 'index'])->name('cash.index');
Route::get('cash/alerts', [CashAlertController::class, 'index'])->name('cash.alerts');
Route::post('cash/accounts', [CashBookController::class, 'storeAccount'])->name('cash.accounts.store');
Route::get('cash/accounts/{cashAccount}', [CashBookController::class, 'show'])->name('cash.accounts.show');
Route::post('cash/accounts/{cashAccount}/opening-balance', [CashBookController::class, 'openingBalance'])->name('cash.accounts.opening-balance');
Route::post('cash/accounts/{cashAccount}/close-day', [CashBookController::class, 'closeDay'])->name('cash.accounts.close-day');
Route::post('cash/accounts/{cashAccount}/reopen-day', [CashBookController::class, 'reopenDay'])->name('cash.accounts.reopen-day');
Route::post('cash/transfers', [CashBookController::class, 'transfer'])->name('cash.transfers.store');

Route::get('cash/hand-over', [RemittanceController::class, 'create'])->name('cash.hand-over');
Route::post('cash/remittances', [RemittanceController::class, 'store'])->name('cash.remittances.store');
Route::get('cash/remittances/{remittance}', [RemittanceController::class, 'show'])->name('cash.remittances.show');
Route::post('cash/remittances/{remittance}/receive', [RemittanceController::class, 'receive'])->name('cash.remittances.receive');
Route::post('cash/remittances/{remittance}/shortage', [RemittanceController::class, 'settleShortage'])->name('cash.remittances.shortage');
