<?php

declare(strict_types=1);

use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

// Self-service registration — must be reachable by guests, so this whole
// file is required from routes/web.php OUTSIDE the ['auth', 'tenant.web']
// group (see that file's comment). See
// .ai/skills/cncms/cncms-context/references/self-service-onboarding.md.
// Self-service account/workspace creation is expensive (provisions a real
// Postgres schema) and must not be automatable — 'throttle:registration'
// (3/hour/IP by default, config/rate-limits.php) is applied to every
// endpoint below that can create a user, a tenant, or both. The Google
// OAuth redirect (which merely bounces to Google) is deliberately left
// unthrottled; only the callback (which creates records) is limited.
Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:registration');

    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('auth.google.callback')
        ->middleware('throttle:registration');
});

// Company-info-only workspace creation for an already-authenticated user
// with no tenant yet (e.g. a fresh Google sign-up) — behind `auth`, NOT
// `tenant.web`, since they don't have a tenant to resolve yet.
Route::middleware('auth')->group(function () {
    Route::get('register/workspace', [RegisterController::class, 'workspace'])->name('register.workspace');
    Route::post('register/workspace', [RegisterController::class, 'storeWorkspace'])->middleware('throttle:registration');
});
