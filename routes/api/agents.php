<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentController;
use Illuminate\Support\Facades\Route;

// Named with an 'api.' prefix so these don't collide with the web
// panel's identically-shaped route names — see routes/web/agents.php.
Route::apiResource('agents', AgentController::class)->names('api.agents');
