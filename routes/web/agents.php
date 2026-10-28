<?php

declare(strict_types=1);

use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

// Field agents (owner, 2026-10-28): a field agent is a user — created,
// renamed, deactivated on the Users page. This page manages their zones,
// and gives an old agent record without a login one.
Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
Route::patch('agents/{agent}/zone', [AgentController::class, 'changeZone'])->name('agents.zone');
Route::post('agents/{agent}/login', [AgentController::class, 'createLogin'])->name('agents.login');
