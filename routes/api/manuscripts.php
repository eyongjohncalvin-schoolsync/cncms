<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ManuscriptController;
use Illuminate\Support\Facades\Route;

// Registered before any future 'manuscripts/{manuscript}'-shaped route so
// the literal 'export' segment isn't swallowed by a route-model-bound
// parameter.
Route::get('manuscripts/export', [ManuscriptController::class, 'export']);
Route::get('manuscripts', [ManuscriptController::class, 'index']);
Route::get('customers/{customer}/manuscripts', [ManuscriptController::class, 'history']);
