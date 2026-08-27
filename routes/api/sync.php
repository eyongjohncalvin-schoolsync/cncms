<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

// Registered before any future 'sync/{something}'-shaped route so literal
// segments ('push', 'pull', 'upload-receipt', 'status') are never swallowed
// by a route-model-bound parameter — mirrors routes/api/manuscripts.php.
//
// 'throttle:sync' (60/min/user, config/rate-limits.php) layers on top of
// the group-level 'throttle:api' (120/min) applied in routes/api.php — the
// tighter sync limit always trips first, so this is the effective ceiling.
Route::middleware('throttle:sync')->group(function () {
    Route::post('sync/push', [SyncController::class, 'push']);
    Route::get('sync/pull', [SyncController::class, 'pull']);
    Route::post('sync/upload-receipt', [SyncController::class, 'uploadReceipt']);
    Route::get('sync/status', [SyncController::class, 'status']);
});
