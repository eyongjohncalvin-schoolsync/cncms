<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

// Named with an 'api.' prefix so these don't collide with the web
// panel's identically-shaped route names (both default to 'zones.index'
// etc. otherwise) — see routes/web/zones.php.
Route::apiResource('zones', ZoneController::class)->names('api.zones');
