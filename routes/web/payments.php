<?php

declare(strict_types=1);

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
Route::get('payments/create', [PaymentController::class, 'create'])->name('payments.create');
Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
Route::post('payments/bulk', [PaymentController::class, 'storeBulk'])->name('payments.bulk-store');
Route::post('payments/bulk-verify', [PaymentController::class, 'bulkVerify'])->name('payments.bulk-verify');
Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
Route::get('payments/{payment}/edit', [PaymentController::class, 'edit'])->name('payments.edit');
Route::put('payments/{payment}', [PaymentController::class, 'update'])->name('payments.update');
Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
Route::post('payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
Route::post('payments/{payment}/receipt', [PaymentController::class, 'uploadReceipt'])->name('payments.receipt');
