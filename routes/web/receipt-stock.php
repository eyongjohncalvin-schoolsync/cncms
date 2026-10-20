<?php

declare(strict_types=1);

use App\Http\Controllers\ReceiptStockController;
use Illuminate\Support\Facades\Route;

// Receipt stock (docs/plans/cash-controls.md, wave 2) — ReceiptBlockPolicy.
Route::get('receipt-stock', [ReceiptStockController::class, 'index'])->name('receipt-stock.index');
Route::post('receipt-stock/books', [ReceiptStockController::class, 'storeBook'])->name('receipt-stock.books.store');
Route::get('receipt-stock/{receiptBlock}/pdf', [ReceiptStockController::class, 'bookPdf'])->name('receipt-stock.pdf');
Route::post('receipt-stock/{receiptBlock}/close', [ReceiptStockController::class, 'close'])->name('receipt-stock.close');
Route::post('receipt-stock/numbers/{number}/cancel', [ReceiptStockController::class, 'cancelLeaf'])
    ->where('number', '[A-Za-z0-9-]+')
    ->name('receipt-stock.numbers.cancel');
Route::post('receipt-stock/devices/{fieldDevice}/revoke', [ReceiptStockController::class, 'revokeDevice'])->name('receipt-stock.devices.revoke');
