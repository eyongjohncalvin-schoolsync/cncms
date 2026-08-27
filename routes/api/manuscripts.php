<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ManuscriptController;
use Illuminate\Support\Facades\Route;

// Registered before any future 'manuscripts/{manuscript}'-shaped route so
// the literal 'export' segment isn't swallowed by a route-model-bound
// parameter.
//
// 'throttle:exports' (10/min/user, config/rate-limits.php) layers on top
// of the group-level 'throttle:api' (120/min) applied in routes/api.php —
// the tighter export limit always trips first, so this is the effective
// ceiling.
Route::get('manuscripts/export', [ManuscriptController::class, 'export'])->middleware('throttle:exports');
Route::get('manuscripts', [ManuscriptController::class, 'index']);
Route::get('customers/{customer}/manuscripts', [ManuscriptController::class, 'history']);
