<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
// The manager inbox is a phone screen; morning summaries sent before
// 2026-11-01 linked the bell to /inbox, so it lands on the dashboard.
Route::redirect('inbox', '/dashboard')->name('inbox');
Route::post('setup-guide/hide', [DashboardController::class, 'hideSetupGuide'])->name('setup-guide.hide');
