<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

// Prepayment offers on sale, at the caller's branch price — the phone caches
// them for offline payment entry. Registered before the resource so
// "prepayment-offers" is never read as a payment uuid.
Route::get('payments/prepayment-offers', [PaymentController::class, 'prepaymentOffers']);

// Named with an 'api.' prefix so these don't collide with the web
// panel's identically-shaped route names — see routes/web/payments.php.
Route::apiResource('payments', PaymentController::class)->names('api.payments');
Route::post('payments/{payment}/verify', [PaymentController::class, 'verify']);
Route::post('payments/{payment}/receipt', [PaymentController::class, 'uploadReceipt']);
