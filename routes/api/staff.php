<?php

declare(strict_types=1);

use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TodayController;
use Illuminate\Support\Facades\Route;

// Today and staff on the phone (docs/plans/mobile-manager-app.md, wave 5) —
// the same policies and services as the dashboard, Money balance and the
// Users Control Center. {tenantUser} binds by id, as on the web.
Route::get('today', [TodayController::class, 'index'])->name('api.today');
Route::get('staff', [StaffController::class, 'index'])->name('api.staff.index');
Route::patch('staff/{tenantUser}/role', [StaffController::class, 'role'])->name('api.staff.role');
Route::post('staff/{tenantUser}/deactivate', [StaffController::class, 'deactivate'])->name('api.staff.deactivate');
Route::post('staff/{tenantUser}/activate', [StaffController::class, 'activate'])->name('api.staff.activate');
Route::post('staff/{tenantUser}/unblock', [StaffController::class, 'unblock'])->name('api.staff.unblock');
