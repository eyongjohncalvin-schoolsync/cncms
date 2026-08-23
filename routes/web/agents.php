<?php

declare(strict_types=1);

use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

Route::resource('agents', AgentController::class)->except('show');
