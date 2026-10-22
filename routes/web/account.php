<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountTwoFactorController;
use Illuminate\Support\Facades\Route;

// "My account" — the signed-in user's own profile and security. Required
// from routes/web.php inside the ['auth', 'tenant.web'] group. Every write
// is `not-impersonating`: a landlord logged in as this user may look but
// never change the person's credentials.
Route::get('account', [AccountController::class, 'show'])->name('account.show');

Route::middleware('not-impersonating')->group(function () {
    Route::patch('account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::put('account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');

    Route::post('account/email', [AccountController::class, 'requestEmailChange'])
        ->middleware('throttle:security-code-send')
        ->name('account.email.request');
    Route::post('account/email/confirm', [AccountController::class, 'confirmEmailChange'])
        ->middleware('throttle:security-code-verify')
        ->name('account.email.confirm');
    Route::delete('account/email', [AccountController::class, 'cancelEmailChange'])->name('account.email.cancel');

    Route::post('account/two-factor', [AccountTwoFactorController::class, 'store'])->name('account.two-factor.store');
    Route::post('account/two-factor/confirm', [AccountTwoFactorController::class, 'confirm'])
        ->middleware('throttle:security-code-verify')
        ->name('account.two-factor.confirm');
    Route::delete('account/two-factor/setup', [AccountTwoFactorController::class, 'cancelSetup'])->name('account.two-factor.cancel');
    Route::delete('account/two-factor', [AccountTwoFactorController::class, 'destroy'])
        ->middleware('throttle:security-code-verify')
        ->name('account.two-factor.destroy');
    Route::post('account/two-factor/recovery-codes', [AccountTwoFactorController::class, 'regenerateRecoveryCodes'])
        ->name('account.two-factor.recovery-codes');

    Route::delete('account/sessions', [AccountController::class, 'signOutOtherSessions'])->name('account.sessions.destroy');
});
