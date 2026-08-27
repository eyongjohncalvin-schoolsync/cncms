<?php

declare(strict_types=1);

use App\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

// 'throttle:audit' (30/min/user, config/rate-limits.php) layers on top of
// the group-level 'throttle:web' (300/min) applied in routes/web.php — the
// tighter audit limit always trips first, so this is the effective
// ceiling.
Route::get('audit/logs', [AuditLogController::class, 'index'])
    ->name('audit.index')
    ->middleware('throttle:audit');
