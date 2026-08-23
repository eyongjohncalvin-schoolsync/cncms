<?php

declare(strict_types=1);

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
Route::get('payments/create', [PaymentController::class, 'create'])->name('payments.create');
Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
Route::post('payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
Route::post('payments/{payment}/receipt', [PaymentController::class, 'uploadReceipt'])->name('payments.receipt');
