<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CashDeskController;
use Illuminate\Support\Facades\Route;

// The cash desk on the phone (Resources phase 2) — see CashDeskController.
Route::get('cash/me', [CashDeskController::class, 'me'])->name('api.cash.me');
Route::get('cash/targets', [CashDeskController::class, 'targets'])->name('api.cash.targets');
Route::post('cash/handovers', [CashDeskController::class, 'startHandover'])->name('api.cash.handovers.store');
Route::post('cash/handovers/{handover}/confirm', [CashDeskController::class, 'confirmHandover'])->name('api.cash.handovers.confirm');
Route::post('cash/handovers/{handover}/cancel', [CashDeskController::class, 'cancelHandover'])->name('api.cash.handovers.cancel');
Route::post('cash/closes', [CashDeskController::class, 'submitClose'])->name('api.cash.closes.store');
Route::post('cash/deposits', [CashDeskController::class, 'deposit'])->name('api.cash.deposits.store');
