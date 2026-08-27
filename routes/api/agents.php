<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentController;
use Illuminate\Support\Facades\Route;

// Static segment first — must be registered before the apiResource's
// GET agents/{agent} below, or "me" would be swallowed as a {agent} uuid
// route-model-binding attempt instead of hitting this action.
Route::get('agents/me', [AgentController::class, 'me']);

// Named with an 'api.' prefix so these don't collide with the web
// panel's identically-shaped route names — see routes/web/agents.php.
Route::apiResource('agents', AgentController::class)->names('api.agents');
