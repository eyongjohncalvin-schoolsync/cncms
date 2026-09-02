<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PaymentReceiptController;
use Illuminate\Support\Facades\Route;

// Wave 2 of payment-receipts-and-whatsapp.md — read-only receipt view +
// PDF download for mobile. Named with an 'api.' prefix so they don't collide
// with the web panel's route names (see routes/web/payments.php).
// {payment}/{receipt} route-model-bind by uuid (#[RouteKey('uuid')]).
Route::get('payments/{payment}/receipt', [PaymentReceiptController::class, 'show'])->name('api.payments.receipt.show');
Route::get('payment-receipts/{receipt}/pdf', [PaymentReceiptController::class, 'downloadPdf'])->name('api.payment-receipts.pdf');
// Wave 3 — manual "Send via WhatsApp" ingredients ({phone, message}) for the
// mobile receipt screen; also logs the send to sent_log.
Route::get('payments/{payment}/receipt/whatsapp-message', [PaymentReceiptController::class, 'whatsappMessage'])->name('api.payments.receipt.whatsapp-message');
