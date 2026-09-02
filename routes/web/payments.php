<?php

declare(strict_types=1);

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentReceiptController;
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

// Business-issued receipt (Wave 2 of payment-receipts-and-whatsapp.md). The
// receipt DATA rides on payments.show's payload; these are just the PDF
// download and the manual issue/re-issue action. The signed PUBLIC PDF link
// is deliberately elsewhere — routes/web/payment-receipts-public.php, outside
// this auth/tenant.web group.
Route::get('payment-receipts/{receipt}/pdf', [PaymentReceiptController::class, 'downloadPdf'])->name('payment-receipts.pdf');
Route::post('payments/{payment}/receipt/issue', [PaymentReceiptController::class, 'issue'])->name('payments.receipt.issue');
// Wave 3 — manual "Send via WhatsApp": records the send to sent_log and
// flashes back a wa.me link the browser opens itself. Gated on payments.view
// inside the controller (a staff member sending a receipt they can see).
Route::post('payments/{payment}/receipt/send-whatsapp', [PaymentReceiptController::class, 'sendWhatsapp'])->name('payments.receipt.send-whatsapp');
