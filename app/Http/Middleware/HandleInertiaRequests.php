<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\NotificationService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shares data with every Inertia response: the authenticated user (central
 * `users` row) plus their resolved tenant role, when App\Http\Middleware\
 * ResolveTenantWeb has already run and bound TenantContext for this
 * request. Mirrors what Api\AuthController::me() exposes over the API, so
 * both frontends see the same shape.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $context = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;

        return [
            ...parent::share($request),
            // Set by App\Http\Middleware\ResolveLocale, which runs earlier
            // in the pipeline (see bootstrap/app.php) so this always
            // reflects the fully-resolved locale, never the raw
            // config('app.locale') default. Fed into react-i18next at
            // bootstrap (resources/tsx/app.tsx) — see
            // .ai/skills/cncms/cncms-context/references/language-support.md
            // section 2.
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user ? [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $context?->role,
                    // Platform-wide flag, independent of tenant role — see
                    // App\Http\Middleware\EnsureLandlord's docblock. Only
                    // controls whether the frontend SHOWS the landlord nav
                    // link; EnsureLandlord itself is the real server-side
                    // gate on the actual routes.
                    'is_landlord' => (bool) $user->is_landlord,
                    // Tenant-scoped Investor tier flag (tenant_users.is_investor)
                    // — see App\Policies\ReportPolicy::view()'s docblock and
                    // references/rbac-permissions.md section 7. Display hint
                    // only, same division of labor as is_landlord above:
                    // controls whether the frontend selects InvestorLayout for
                    // Reports/Index.tsx; ReportPolicy is the real server-side
                    // gate on the /reports route itself.
                    'is_investor' => (bool) $context?->tenantUser->is_investor,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Structured row-level report from a bulk zone/customer
                // import (App\Services\ZoneImportService/CustomerImportService)
                // — {type, succeeded_count, failed_count, failed: [{row, reason}]}.
                // Kept separate from the plain success/error strings above
                // so Zones/Index and Customers/Index can render a proper
                // report table instead of a flattened one-line message.
                'import' => fn () => $request->session()->get('import'),
            ],
            // Bell dropdown + emergency banner data (in-app-notifications.md
            // section 4) — a closure so it's re-evaluated on every request,
            // including the partial reloads resources/tsx/layouts/AppLayout.tsx
            // drives via usePoll(20000, { only: ['notifications'] }). null
            // when there's no authenticated tenant-scoped user (matches the
            // 'auth.user' null case above) rather than an empty shape, so the
            // frontend can tell "not logged in" apart from "logged in, zero
            // notifications".
            'notifications' => $user && $context
                ? fn () => $this->notifications->feedForUser($user, $context)
                : null,
        ];
    }
}
