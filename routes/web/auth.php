<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LoginCodeController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'create'])->name('login');

    // Brute-force protected: 5/min/IP, shared with the API login limiter
    // (see config/rate-limits.php, api-spec.md section 11).
    Route::post('login', [AuthController::class, 'store'])->middleware('throttle:login');

    // Passwordless sign-in with a 6-digit code sent by email.
    Route::get('login/code', [LoginCodeController::class, 'create'])->name('login-code.create');
    Route::post('login/code', [LoginCodeController::class, 'send'])
        ->middleware('throttle:security-code-send')
        ->name('login-code.send');
    Route::post('login/code/verify', [LoginCodeController::class, 'verify'])
        ->middleware('throttle:security-code-verify')
        ->name('login-code.verify');

    // "Forgot password?": a 6-digit reset code by email, then a new password.
    Route::get('forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'send'])
        ->middleware('throttle:security-code-send')
        ->name('password.email');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:security-code-verify')
        ->name('password.update');
    Route::delete('forgot-password', [PasswordResetController::class, 'cancel'])->name('password.cancel');
    Route::delete('login/code', [LoginCodeController::class, 'reset'])->name('login-code.reset');

    // Second sign-in step for users with two-factor on. Still `guest`: the
    // user is not signed in until this passes.
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:security-code-verify')
        ->name('two-factor.challenge.store');
    Route::post('two-factor-challenge/email', [TwoFactorChallengeController::class, 'sendEmail'])
        ->middleware('throttle:security-code-send')
        ->name('two-factor.challenge.email');
});

Route::post('logout', [AuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
