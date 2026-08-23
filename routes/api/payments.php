<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

// Named with an 'api.' prefix so these don't collide with the web
// panel's identically-shaped route names — see routes/web/payments.php.
Route::apiResource('payments', PaymentController::class)->names('api.payments');
Route::post('payments/{payment}/verify', [PaymentController::class, 'verify']);
Route::post('payments/{payment}/receipt', [PaymentController::class, 'uploadReceipt']);
