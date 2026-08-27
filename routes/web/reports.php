<?php

declare(strict_types=1);

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

// 'throttle:exports' (10/min/user, config/rate-limits.php) layers on top of
// the group-level 'throttle:web' (300/min) applied in routes/web.php — same
// convention as manuscripts/export in routes/web/manuscripts.php.
Route::get('reports/export', [ReportController::class, 'export'])
    ->name('reports.export')
    ->middleware('throttle:exports');
