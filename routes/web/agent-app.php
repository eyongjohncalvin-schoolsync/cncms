<?php

declare(strict_types=1);

use App\Http\Controllers\AgentAppController;
use Illuminate\Support\Facades\Route;

// "Get the Agent App" — mobile build download / install page. Role gating
// (agent/manager/admin/super) is enforced in the controller, not here.
Route::get('agent-app', [AgentAppController::class, 'show'])->name('agent-app.show');
