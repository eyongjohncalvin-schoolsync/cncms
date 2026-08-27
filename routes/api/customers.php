<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CustomerController;
use Illuminate\Support\Facades\Route;

// Registered ahead of the apiResource below so the literal
// 'eligible-for-disconnection' segment isn't swallowed by the
// apiResource's 'customers/{customer}' show route (which would otherwise
// try to route-model-bind a Customer with uuid
// "eligible-for-disconnection" and 404). Mirrors
// DisconnectionsController::eligibilityIndex()'s web `?eligible=1` tab —
// see CustomerController::eligibleForDisconnection()'s doc comment.
Route::get('customers/eligible-for-disconnection', [CustomerController::class, 'eligibleForDisconnection']);

// Named with an 'api.' prefix so these don't collide with the web
// panel's identically-shaped route names — see routes/web/customers.php.
Route::apiResource('customers', CustomerController::class)->names('api.customers');
Route::patch('customers/{customer}/disconnect', [CustomerController::class, 'disconnect']);
Route::patch('customers/{customer}/suspend', [CustomerController::class, 'suspend']);
Route::patch('customers/{customer}/reconnect', [CustomerController::class, 'reconnect']);
