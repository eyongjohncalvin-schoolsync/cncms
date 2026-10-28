<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentReversalRequestController;
use Illuminate\Support\Facades\Route;

// Prepayment offers on sale, at the caller's branch price — the phone caches
// them for offline payment entry. Registered before the resource so
// "prepayment-offers" is never read as a payment uuid.
Route::get('payments/prepayment-offers', [PaymentController::class, 'prepaymentOffers']);
// Payments this month in the agent's zones that someone else took — usually at the office (owner, 2026-10-28).
Route::get('payments/by-others', [PaymentController::class, 'byOthers']);

// Named with an 'api.' prefix so these don't collide with the web
// panel's identically-shaped route names — see routes/web/payments.php.
Route::apiResource('payments', PaymentController::class)->names('api.payments');
Route::post('payments/{payment}/verify', [PaymentController::class, 'verify']);
// The phone asks, when it has signal, before saving a payment (owner, 2026-10-28).
Route::get('customers/{customer}/payment-check', [PaymentController::class, 'check']);
Route::post('payments/{payment}/receipt', [PaymentController::class, 'uploadReceipt']);
// The collector asks the office to reverse one of their payments (owner, 2026-10-28).
Route::post('payments/{payment}/reversal-request', [PaymentReversalRequestController::class, 'store']);
