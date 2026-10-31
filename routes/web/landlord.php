<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Landlord\ActivityController;
use App\Http\Controllers\Landlord\BackupController;
use App\Http\Controllers\Landlord\DashboardController;
use App\Http\Controllers\Landlord\ImpersonationController;
use App\Http\Controllers\Landlord\LandlordController;
use App\Http\Controllers\Landlord\LegalDocumentController;
use App\Http\Controllers\Landlord\MaintenanceWindowController;
use App\Http\Controllers\Landlord\TenantController;
use App\Http\Controllers\Landlord\UserController;
use App\Models\LegalDocumentVersion;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landlord (central/platform-level) routes
|--------------------------------------------------------------------------
|
| Distinct from the tenant-scoped Inertia pages grouped under
| ['auth', 'tenant.web'] in routes/web.php: these pages manage the
| `tenants` table itself (onboarding future LCO clients ShalomTech may
| take on), so they must run on the CENTRAL connection only and must
| never go through App\Http\Middleware\ResolveTenantWeb, which forces the
| request into one specific tenant's schema. Gated instead by the
| `landlord` middleware alias (App\Http\Middleware\EnsureLandlord).
|
*/
Route::middleware(['auth', 'landlord'])->prefix('landlord')->name('landlord.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

    // The landlord's own My account (profile, password, email, 2FA,
    // devices) — same page and actions as /account, no workspace needed.
    Route::get('account', [AccountController::class, 'show'])->name('account.show');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    // Support reset of a locked-out user's authenticator two-factor.
    Route::post('users/{user}/two-factor/reset', [UserController::class, 'resetTwoFactor'])
        ->middleware('not-impersonating')
        ->name('users.two-factor.reset');
    Route::post('impersonate/{user}', [ImpersonationController::class, 'start'])->name('impersonate');

    Route::get('landlords', [LandlordController::class, 'index'])->name('landlords.index');
    Route::post('landlords', [LandlordController::class, 'store'])->name('landlords.store');
    Route::delete('landlords/{user}', [LandlordController::class, 'destroy'])->name('landlords.destroy');

    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
    Route::patch('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::post('tenants/{tenant}/approve', [TenantController::class, 'approve'])->name('tenants.approve');
    Route::post('tenants/{tenant}/reject', [TenantController::class, 'reject'])->name('tenants.reject');
    Route::patch('tenants/{tenant}/demo', [TenantController::class, 'updateDemo'])->name('tenants.demo');
    Route::post('tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');

    Route::get('legal', [LegalDocumentController::class, 'index'])->name('legal.index');
    Route::get('legal/{type}', [LegalDocumentController::class, 'edit'])->whereIn('type', LegalDocumentVersion::TYPES)->name('legal.edit');
    Route::put('legal/{type}/draft', [LegalDocumentController::class, 'saveDraft'])->whereIn('type', LegalDocumentVersion::TYPES)->name('legal.draft.save');
    Route::delete('legal/{type}/draft', [LegalDocumentController::class, 'discardDraft'])->whereIn('type', LegalDocumentVersion::TYPES)->name('legal.draft.discard');
    Route::post('legal/{type}/publish', [LegalDocumentController::class, 'publish'])->whereIn('type', LegalDocumentVersion::TYPES)->name('legal.publish');
    Route::get('legal/{type}/preview/{version}', [LegalDocumentController::class, 'preview'])->whereIn('type', LegalDocumentVersion::TYPES)->where('version', 'draft|[0-9]+')->name('legal.preview');

    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('backups', [BackupController::class, 'store'])->middleware('throttle:exports')->name('backups.store');
    Route::post('backups/{backup}/download', [BackupController::class, 'download'])->middleware('throttle:exports')->name('backups.download');

    Route::get('maintenance', [MaintenanceWindowController::class, 'index'])->name('maintenance.index');
    Route::post('maintenance', [MaintenanceWindowController::class, 'store'])->name('maintenance.store');
    Route::patch('maintenance/{maintenanceWindow}', [MaintenanceWindowController::class, 'update'])->name('maintenance.update');
    Route::post('maintenance/{maintenanceWindow}/end', [MaintenanceWindowController::class, 'end'])->name('maintenance.end');
});

// Ending an impersonation: only `auth`, not `landlord` — while impersonating,
// the signed-in user is the workspace user (App\Support\Impersonation).
Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])
    ->middleware('auth')
    ->name('impersonation.stop');
