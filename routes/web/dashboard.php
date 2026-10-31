<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::post('setup-guide/hide', [DashboardController::class, 'hideSetupGuide'])->name('setup-guide.hide');
