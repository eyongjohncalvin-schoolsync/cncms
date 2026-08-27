<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth endpoints. Login must stay outside ResolveTenant — there is no
    // authenticated user yet to resolve a tenant for. Brute-force
    // protected: 5/min/IP (see config/rate-limits.php, api-spec.md
    // section 11).
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

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
            require __DIR__.'/api/payments.php';
            require __DIR__.'/api/agents.php';
            require __DIR__.'/api/manuscripts.php';
            require __DIR__.'/api/bills.php';
            require __DIR__.'/api/sync.php';
            require __DIR__.'/api/audit.php';
            require __DIR__.'/api/resources.php';
            require __DIR__.'/api/complaints.php';
            require __DIR__.'/api/notifications.php';
            require __DIR__.'/api/devices.php';
        });
    });
});
