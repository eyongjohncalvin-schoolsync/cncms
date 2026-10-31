<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountSecurityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LoginCodeController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\TwoFactorChallengeController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth endpoints. Login must stay outside ResolveTenant — there is no
    // authenticated user yet to resolve a tenant for. Brute-force
    // protected: 5/min/IP (see config/rate-limits.php, api-spec.md
    // section 11).
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Second sign-in step for two-factor users (challenge_token from the
    // login response), and passwordless sign-in with an emailed code. All
    // before any token exists, so no auth:sanctum.
    Route::post('auth/two-factor', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:security-code-verify');
    Route::post('auth/two-factor/email', [TwoFactorChallengeController::class, 'sendEmail'])->middleware('throttle:security-code-send');
    Route::post('auth/login-code', [LoginCodeController::class, 'send'])->middleware('throttle:security-code-send');
    Route::post('auth/login-code/verify', [LoginCodeController::class, 'verify'])->middleware('throttle:security-code-verify');

    // "Forgot password?" with an emailed code (for a later mobile build).
    Route::post('auth/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:security-code-send');
    Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:security-code-verify');

    Route::middleware('auth:sanctum')->group(function () {
        // The signed-in user's own account: the general `api` limit, plus
        // the stricter ones per route below. (The tenant group further down
        // has its own `throttle:api`, so it stays outside this one.)
        Route::middleware('throttle:api')->group(function () {
            Route::post('auth/logout', [AuthController::class, 'logout']);

            // Account security (central `users` row only, so no tenant): email
            // change with a code, authenticator-app two-factor, sessions.
            Route::get('auth/security', [AccountSecurityController::class, 'show']);
            Route::post('auth/email', [AccountSecurityController::class, 'requestEmailChange'])->middleware('throttle:security-code-send');
            Route::post('auth/email/confirm', [AccountSecurityController::class, 'confirmEmailChange'])->middleware('throttle:security-code-verify');
            Route::delete('auth/email', [AccountSecurityController::class, 'cancelEmailChange']);
            Route::post('auth/two-factor/setup', [AccountSecurityController::class, 'setupTwoFactor']);
            Route::delete('auth/two-factor/setup', [AccountSecurityController::class, 'cancelTwoFactorSetup']);
            Route::post('auth/two-factor/confirm', [AccountSecurityController::class, 'confirmTwoFactor'])->middleware('throttle:security-code-verify');
            Route::delete('auth/two-factor', [AccountSecurityController::class, 'disableTwoFactor'])->middleware('throttle:security-code-verify');
            Route::post('auth/two-factor/recovery-codes', [AccountSecurityController::class, 'regenerateRecoveryCodes']);
            Route::get('auth/sessions', [AccountSecurityController::class, 'sessions']);
            Route::delete('auth/sessions', [AccountSecurityController::class, 'signOutOtherSessions']);
            // Self-service profile/password endpoints — gated by auth:sanctum
            // only (no ResolveTenant/tenant membership needed), same placement
            // as logout() above: both only ever touch the central `users`
            // table, never tenant-scoped data, so there's no tenant to resolve.
            Route::patch('auth/profile', [AuthController::class, 'updateProfile']);
            // Quiet hours for phone alerts (docs/plans/mobile-manager-app.md, wave 2).
            Route::get('auth/alerts', [AuthController::class, 'alertSettings']);
            Route::put('auth/alerts', [AuthController::class, 'updateAlertSettings']);
            // Checks the current password, so as strict as a code check.
            Route::patch('auth/password', [AuthController::class, 'updatePassword'])->middleware('throttle:security-code-verify');
        });

        // Every other /api/v1/* endpoint requires both a valid Sanctum
        // token AND tenant membership. ResolveTenant initializes tenancy
        // and verifies the tenant_users row; see its class doc for the
        // single-tenant scope note.
        //
        // 'throttle:api' (120/min/token, config/rate-limits.php) is the
        // default for everything in this group. Sync, export, and audit
        // routes layer an additional, tighter limiter on top directly in
        // their own route files (routes/api/sync.php, manuscripts.php,
        // bills.php, audit.php) — the tighter limiter always trips first,
        // so the effective ceiling on those specific endpoints is exactly
        // the documented number.
        Route::middleware(['throttle:api', ResolveTenant::class])->group(function () {
            // Diagnostic "who am I" endpoint — also doubles as the
            // reference example for reading the resolved tenant role
            // downstream (see AuthController::me() and TenantContext).
            Route::get('auth/me', [AuthController::class, 'me']);

            // Each resource area owns its own route file under routes/api/
            // (zones.php, customers.php, payments.php, ...) so independent
            // workstreams never collide on this one file.
            // {zone}/{customer}/{payment}/{agent} route-model-bind by uuid
            // automatically — see the #[RouteKey('uuid')] attribute on each
            // model.
            require __DIR__.'/api/zones.php';
            require __DIR__.'/api/customers.php';
            require __DIR__.'/api/services.php';
            require __DIR__.'/api/company.php';
            require __DIR__.'/api/payments.php';
            require __DIR__.'/api/payment-receipts.php';
            require __DIR__.'/api/agents.php';
            require __DIR__.'/api/manuscripts.php';
            require __DIR__.'/api/bills.php';
            require __DIR__.'/api/sync.php';
            require __DIR__.'/api/audit.php';
            require __DIR__.'/api/resources.php';
            require __DIR__.'/api/complaints.php';
            require __DIR__.'/api/arrears-adjustments.php';
            require __DIR__.'/api/notifications.php';
            require __DIR__.'/api/devices.php';
            require __DIR__.'/api/field-receipts.php';
            require __DIR__.'/api/remittances.php';
            require __DIR__.'/api/inbox.php';
        });
    });
});
