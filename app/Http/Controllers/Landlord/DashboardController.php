<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\LandlordActivity;
use App\Models\Tenant;
use App\Models\TenantUserIndex;
use App\Models\User;
use App\Support\MaintenanceMode;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page of the landlord area: platform-wide counts, workspaces
 * waiting for approval, the maintenance window, and the latest landlord
 * activity. Central data only — no tenant is ever initialized here.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        // Tenant flags live in the `data` JSON column (VirtualColumn), so
        // they are counted in memory — there are only a handful of tenants.
        $tenants = Tenant::query()->get();

        $pending = $tenants
            ->filter(fn (Tenant $tenant) => $tenant->registration_status === 'pending')
            ->sortBy('created_at')
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'created_at' => $tenant->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Landlord/Dashboard', [
            'stats' => [
                'tenants' => $tenants->count(),
                'active' => $tenants->filter(fn (Tenant $tenant) => $tenant->isApproved() && $tenant->is_active)->count(),
                'pending' => $pending->count(),
                'inactive' => $tenants->filter(fn (Tenant $tenant) => ! $tenant->is_active)->count(),
                'users' => TenantUserIndex::query()->distinct()->count('user_id'),
                'landlords' => User::query()->where('is_landlord', true)->count(),
            ],
            'pending_tenants' => $pending->take(5)->all(),
            'maintenance_window' => MaintenanceMode::current()?->toNotice(),
            'recent_activity' => ActivityController::shape(
                LandlordActivity::query()->with('user:id,name')->latest('created_at')->latest('id')->limit(8)->get(),
            ),
        ]);
    }
}
