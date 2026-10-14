<?php

declare(strict_types=1);

use App\Http\Controllers\CashDeskController;
use Illuminate\Support\Facades\Route;

// Resources → Cash desk (phase 2) — see CashDeskController.
Route::get('cash', [CashDeskController::class, 'index'])->name('cash.index');
Route::post('cash/handovers', [CashDeskController::class, 'startHandover'])->name('cash.handovers.store');
Route::post('cash/handovers/{handover}/confirm', [CashDeskController::class, 'confirmHandover'])->name('cash.handovers.confirm');
Route::post('cash/handovers/{handover}/cancel', [CashDeskController::class, 'cancelHandover'])->name('cash.handovers.cancel');
Route::post('cash/closes', [CashDeskController::class, 'submitClose'])->name('cash.closes.store');
Route::post('cash/closes/{close}/decide', [CashDeskController::class, 'decideClose'])->name('cash.closes.decide');
Route::post('cash/deposits', [CashDeskController::class, 'deposit'])->name('cash.deposits.store');
Route::post('cash/deposits/{deposit}/decide', [CashDeskController::class, 'decideDeposit'])->name('cash.deposits.decide');
Route::get('cash/deposits/{deposit}/slip', [CashDeskController::class, 'depositSlip'])->name('cash.deposits.slip');
Route::post('cash/accounts', [CashDeskController::class, 'storeAccount'])->name('cash.accounts.store');
Route::patch('cash/accounts/{account}', [CashDeskController::class, 'updateAccount'])->name('cash.accounts.update');
Route::post('cash/periods/lock', [CashDeskController::class, 'lockPeriod'])->name('cash.periods.lock');
Route::post('cash/verify', [CashDeskController::class, 'verifyLedger'])->name('cash.verify');
