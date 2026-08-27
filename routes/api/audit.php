<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuditLogController;
use Illuminate\Support\Facades\Route;

// 'throttle:audit' (30/min/user, config/rate-limits.php) layers on top of
// the group-level 'throttle:api' (120/min) applied in routes/api.php — the
// tighter audit limit always trips first, so this is the effective
// ceiling.
Route::get('audit/logs', [AuditLogController::class, 'index'])->middleware('throttle:audit');
