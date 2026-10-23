<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountTwoFactorController;
use Illuminate\Support\Facades\Route;

// "My account" — the WRITE actions (profile, password, email, 2FA,
// sessions). They touch only the signed-in user's central row, so they need
// `auth` but no workspace: the workspace page (/account, routes/web/
// account.php) and the landlord page (/landlord/account, routes/web/
// landlord.php) both post here. Required from routes/web.php inside an
// ['auth', 'throttle:web'] group. Still refused while a landlord is
// impersonating — they may look but never change the person's credentials.
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
