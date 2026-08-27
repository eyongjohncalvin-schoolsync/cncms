<?php

declare(strict_types=1);

use App\Http\Controllers\Landlord\TenantController;
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
    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
    Route::patch('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::post('tenants/{tenant}/approve', [TenantController::class, 'approve'])->name('tenants.approve');
    Route::post('tenants/{tenant}/reject', [TenantController::class, 'reject'])->name('tenants.reject');
});
