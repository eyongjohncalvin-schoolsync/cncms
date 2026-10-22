<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\MaintenanceMode;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The countdown page ResolveTenantWeb holds tenant users on while a
 * maintenance window is active (App\Support\MaintenanceMode). Like
 * WorkspaceController::pending(), deliberately NOT behind tenant.web —
 * that middleware is what redirects here.
 */
class MaintenanceController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        $window = MaintenanceMode::active();

        if (! $window) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Maintenance', [
            'window' => $window->toNotice(),
        ]);
    }
}
