<?php

declare(strict_types=1);

use App\Http\Controllers\SendQueueController;
use Illuminate\Support\Facades\Route;

Route::get('to-send', [SendQueueController::class, 'index'])->name('send-queue.index');
Route::post('to-send/receipts/{receipt}/skip', [SendQueueController::class, 'skipReceipt'])->name('send-queue.receipts.skip');
Route::post('to-send/bills/{manuscript}/skip', [SendQueueController::class, 'skipBill'])->name('send-queue.bills.skip');
