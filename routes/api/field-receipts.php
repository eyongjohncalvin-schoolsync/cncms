<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FieldReceiptController;
use Illuminate\Support\Facades\Route;

// Cash controls (docs/plans/cash-controls.md): a phone registers once for
// receipts, then draws its system-issued receipt numbers on every sync and
// can cancel one it burned without a payment. See FieldReceiptController.
Route::post('field-devices', [FieldReceiptController::class, 'register'])->name('api.field-devices.register');
Route::get('receipt-stock', [FieldReceiptController::class, 'stock'])->name('api.receipt-stock');
Route::post('receipt-numbers/{number}/cancel', [FieldReceiptController::class, 'cancel'])
    ->where('number', '[A-Za-z0-9-]+')
    ->name('api.receipt-numbers.cancel');
