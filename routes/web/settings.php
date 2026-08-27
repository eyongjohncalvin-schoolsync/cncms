<?php

declare(strict_types=1);

use App\Http\Controllers\SettingsBillPrintingController;
use App\Http\Controllers\SettingsCommandRunController;
use App\Http\Controllers\SettingsCompanyController;
use App\Http\Controllers\SettingsLocaleController;
use App\Http\Controllers\SettingsNotificationController;
use App\Http\Controllers\SettingsUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings routes
|--------------------------------------------------------------------------
|
| Company Info, Users & Roles, Command Runs — all admin-only per
| web-admin-spec.md's nav spec ("SETTINGS [admin only]"). Policy checks in
| each controller enforce this server-side; the sidebar link is also
| client-side gated (see resources/tsx/layouts/AppLayout.tsx) but that's
| a UX nicety, not the source of truth.
|
*/
Route::get('settings/company', [SettingsCompanyController::class, 'edit'])->name('settings.company.edit');
Route::patch('settings/company', [SettingsCompanyController::class, 'update'])->name('settings.company.update');

Route::get('settings/users', [SettingsUserController::class, 'index'])->name('settings.users.index');
Route::post('settings/users', [SettingsUserController::class, 'store'])->name('settings.users.store');
Route::patch('settings/users/{tenantUser}', [SettingsUserController::class, 'update'])->name('settings.users.update');
Route::post('settings/users/{tenantUser}/deactivate', [SettingsUserController::class, 'deactivate'])->name('settings.users.deactivate');

Route::get('settings/command-runs', [SettingsCommandRunController::class, 'index'])->name('settings.command-runs.index');
Route::patch('settings/command-runs/schedule', [SettingsCommandRunController::class, 'updateSchedule'])->name('settings.command-runs.schedule.update');
Route::post('settings/command-runs/{run}/publish', [SettingsCommandRunController::class, 'publish'])->name('settings.command-runs.publish');

// Language switcher (resources/tsx/layouts/AppLayout.tsx) — updates the
// caller's own `users.locale`, available to every authenticated tenant
// member regardless of role (unlike the routes above, which are
// admin-only).
Route::patch('settings/locale', [SettingsLocaleController::class, 'update'])->name('settings.locale.update');

Route::get('settings/notifications', [SettingsNotificationController::class, 'edit'])->name('settings.notifications.edit');
Route::patch('settings/notifications', [SettingsNotificationController::class, 'update'])->name('settings.notifications.update');

// Bill Printing — template + N-up density settings and the live PDF
// preview (this cycle's design review). The {template} preview route is
// placed above the plain edit/update pair since it's the more specific
// path; Laravel route matching order doesn't actually require this here
// (no overlapping segments), but it groups the three related routes
// together for readability.
Route::get('settings/bill-printing', [SettingsBillPrintingController::class, 'edit'])->name('settings.bill-printing.edit');
Route::patch('settings/bill-printing', [SettingsBillPrintingController::class, 'update'])->name('settings.bill-printing.update');
Route::get('settings/bill-printing/preview/{template}', [SettingsBillPrintingController::class, 'preview'])->name('settings.bill-printing.preview');
