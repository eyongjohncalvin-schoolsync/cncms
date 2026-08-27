<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CustomerController;
use Illuminate\Support\Facades\Route;

// Named with an 'api.' prefix so these don't collide with the web
// panel's identically-shaped route names — see routes/web/customers.php.
Route::apiResource('customers', CustomerController::class)->names('api.customers');
Route::patch('customers/{customer}/disconnect', [CustomerController::class, 'disconnect']);
Route::patch('customers/{customer}/suspend', [CustomerController::class, 'suspend']);
Route::patch('customers/{customer}/reconnect', [CustomerController::class, 'reconnect']);
