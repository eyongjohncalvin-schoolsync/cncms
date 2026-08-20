<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ZoneController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth endpoints. Login must stay outside ResolveTenant — there is no
    // authenticated user yet to resolve a tenant for.
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Every other /api/v1/* endpoint requires both a valid Sanctum
        // token AND tenant membership. ResolveTenant initializes tenancy
        // and verifies the tenant_users row; see its class doc for the
        // single-tenant scope note.
        Route::middleware(ResolveTenant::class)->group(function () {
            // Diagnostic "who am I" endpoint — also doubles as the
            // reference example for reading the resolved tenant role
            // downstream (see AuthController::me() and TenantContext).
            Route::get('auth/me', [AuthController::class, 'me']);

            // CRUD workstreams register their routes here (customers,
            // payments, agents, zones, manuscripts, resources, etc.).
            // {zone}/{customer}/{payment}/{agent} route-model-bind by uuid
            // automatically — see the #[RouteKey('uuid')] attribute on each
            // model.
            Route::apiResource('zones', ZoneController::class);
            Route::apiResource('customers', CustomerController::class);
            Route::apiResource('payments', PaymentController::class);
            Route::apiResource('agents', AgentController::class);
        });
    });
});
