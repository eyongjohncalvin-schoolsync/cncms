<?php

declare(strict_types=1);

use App\Http\Controllers\OpeningBalanceController;
use Illuminate\Support\Facades\Route;

Route::get('opening-balances', [OpeningBalanceController::class, 'index'])->name('opening-balances.index');
Route::post('opening-balances', [OpeningBalanceController::class, 'store'])->name('opening-balances.store');
Route::patch('opening-balances/{openingBalanceBatch}', [OpeningBalanceController::class, 'update'])->name('opening-balances.update');
Route::delete('opening-balances/{openingBalanceBatch}', [OpeningBalanceController::class, 'destroy'])->name('opening-balances.destroy');
Route::put('opening-balances/{openingBalanceBatch}/entries', [OpeningBalanceController::class, 'saveEntries'])->name('opening-balances.entries');
Route::post('opening-balances/{openingBalanceBatch}/submit', [OpeningBalanceController::class, 'submit'])->name('opening-balances.submit');
Route::post('opening-balances/{openingBalanceBatch}/approve', [OpeningBalanceController::class, 'approve'])->name('opening-balances.approve');
Route::post('opening-balances/{openingBalanceBatch}/reject', [OpeningBalanceController::class, 'reject'])->name('opening-balances.reject');
Route::post('opening-balances/{openingBalanceBatch}/reopen', [OpeningBalanceController::class, 'reopen'])->name('opening-balances.reopen');
