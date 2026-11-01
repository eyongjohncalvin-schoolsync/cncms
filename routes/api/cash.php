<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CashController;
use Illuminate\Support\Facades\Route;

// Cash on the phone (docs/plans/mobile-manager-app.md, wave 4) — the same
// services and policies as routes/web/cash.php.
Route::get('cash', [CashController::class, 'index'])->name('api.cash.index');
Route::get('cash/remittances/{remittance}', [CashController::class, 'remittance'])->name('api.cash.remittances.show');
Route::post('cash/remittances/{remittance}/receive', [CashController::class, 'receive'])->name('api.cash.remittances.receive');
Route::post('cash/remittances/{remittance}/shortage', [CashController::class, 'settleRemittanceShortage'])->name('api.cash.remittances.shortage');
Route::post('cash/shortages/{cashShortage}/settle', [CashController::class, 'settleShortage'])->name('api.cash.shortages.settle');
Route::post('cash/accounts/{cashAccount}/close-day', [CashController::class, 'closeDay'])->name('api.cash.accounts.close-day');
Route::post('cash/transfers/{cashTransfer}/accept', [CashController::class, 'accept'])->name('api.cash.transfers.accept');
Route::post('cash/transfers/{cashTransfer}/refuse', [CashController::class, 'refuse'])->name('api.cash.transfers.refuse');
Route::post('cash/transfers/{cashTransfer}/recall', [CashController::class, 'recall'])->name('api.cash.transfers.recall');
Route::post('cash/transfers/{cashTransfer}/confirm', [CashController::class, 'confirm'])->name('api.cash.transfers.confirm');
Route::post('cash/transfers/{cashTransfer}/refuse-deposit', [CashController::class, 'refuseDeposit'])->name('api.cash.transfers.refuse-deposit');
