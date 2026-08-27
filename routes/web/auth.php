<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'create'])->name('login');

    // Brute-force protected: 5/min/IP, shared with the API login limiter
    // (see config/rate-limits.php, api-spec.md section 11).
    Route::post('login', [AuthController::class, 'store'])->middleware('throttle:login');
});

Route::post('logout', [AuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
