<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Tenant;
use App\Services\ContractService;
use App\Services\NotificationService;
use App\Support\Impersonation;
use App\Support\MaintenanceMode;
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
                    // Branches v2: fenced to one branch (drives the "My branch" nav link).
                    'branch_fenced' => (bool) $context?->isFenced(),
                    // Tenant-scoped Investor tier flag (tenant_users.is_investor)
                    // — see App\Policies\ReportPolicy::view()'s docblock and
                    // references/rbac-permissions.md section 7. Display hint
                    // only, same division of labor as is_landlord above:
                    // controls whether the frontend selects InvestorLayout for
                    // Reports/Index.tsx; ReportPolicy is the real server-side
                    // gate on the /reports route itself.
                    'is_investor' => (bool) $context?->tenantUser->is_investor,
                    // RBAC v2 (docs/plans/rbac-v2-configurable-roles.md): the
                    // resolved permission list for this user's role, or
                    // ['*'] for a super role. Wave 1 only SHARES it — no
                    // frontend consumer yet (AppNav still keys off role
                    // arrays); Wave 4 swaps those to permissions.includes().
                    // Mirrored by Api\AuthController::me().
                    'permissions' => $context ? $context->permissions() : [],
                ] : null,
            ],
            // The resolved tenant's company name, for the app chrome
            // (AppLayout's sidebar). null before a tenant is resolved — the
            // public auth pages are platform-branded, NOT tenant-branded, so
            // they must never show a company name. Closure so it's only
            // resolved (and only queries the tenant `companies` table) when
            // tenancy is actually initialized.
            'company' => $context
                ? fn () => [
                    'name' => Company::cached()?->name ?? tenant()?->name,
                    // Shown in the sidebar in place of the CNCMS mark.
                    'logo_url' => Company::cached()?->logoUrl(),
                ]
                : null,
            // Branch switcher (branches v2 §8): only for someone not fenced to
            // a branch, and only once the company has more than one branch —
            // a one-town company never sees it.
            'branchView' => $context !== null && ! $context->isFenced()
                ? fn (): ?array => $this->branchView($context)
                : null,
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
                // The wa.me deep link flashed by PaymentReceiptController::
                // sendWhatsapp() (Wave 3 of payment-receipts-and-whatsapp.md)
                // — Payments/Show.tsx opens it in a new tab on the POST's
                // success callback, then it's gone on the next visit.
                'whatsapp_url' => fn () => $request->session()->get('whatsapp_url'),
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
            // The running or next scheduled platform maintenance window
            // (App\Support\MaintenanceMode) — drives the countdown banner
            // on every layout, the login page included, so staff hear
            // about it before they are locked out. Polled with
            // `notifications` by AppLayout.
            'maintenance' => fn () => MaintenanceMode::current()?->toNotice(),
            // The free demo's countdown (last 30 days, super/admin only) and,
            // once it has ended, the read-only notice for everyone
            // (Tenant::isReadOnly, App\Support\DemoReadOnly).
            'demo' => $context ? fn (): ?array => $this->demoNotice($context) : null,
            // The published service contract version the company has not
            // signed yet (App\Services\ContractService) — super/admin only,
            // drives the "please sign" strip linking to Settings → Contract.
            'contractPending' => $context && ($context->isSuper() || $context->is('admin'))
                ? fn (): ?int => app(ContractService::class)->awaitingSignature((string) tenant('id'))?->version
                : null,
            // Set while a landlord is logged in as this user
            // (App\Support\Impersonation) — drives AppLayout's red banner.
            'impersonation' => fn () => Impersonation::active() && $user
                ? ['impersonator' => Impersonation::impersonator()?->name, 'user' => $user->name]
                : null,
        ];
    }

    /**
     * @return array{ends_on: string, days_left: int, read_only: bool, phone: string, whatsapp: string}|null
     */
    private function demoNotice(TenantContext $context): ?array
    {
        $tenant = tenant();
        $daysLeft = $tenant instanceof Tenant ? $tenant->demoDaysLeft() : null;

        if ($daysLeft === null) {
            return null;
        }

        $readOnly = $daysLeft < 0;
        $isAdmin = $context->isSuper() || $context->is('admin');

        if (! $readOnly && ($daysLeft > 30 || ! $isAdmin)) {
            return null;
        }

        return [
            'ends_on' => (string) $tenant->demo_ends_on,
            'days_left' => $daysLeft,
            'read_only' => $readOnly,
            'phone' => (string) config('site.phone'),
            'whatsapp' => (string) config('site.whatsapp'),
        ];
    }

    /**
     * @return array{branches: list<array{uuid: string, name: string, is_headquarters: bool}>, current: ?string}|null
     */
    private function branchView(TenantContext $context): ?array
    {
        // The request's branch memo (already loaded by most pages): no query of its own.
        $branches = Branch::allMemoized()->sortBy([['is_headquarters', 'desc'], ['name', 'asc']])->values();

        if ($branches->count() < 2) {
            return null;
        }

        return [
            'branches' => $branches->map(fn (Branch $branch): array => [
                'uuid' => $branch->uuid,
                'name' => $branch->name,
                'is_headquarters' => $branch->is_headquarters,
            ])->values()->all(),
            'current' => $context->branchChosen ? $branches->firstWhere('id', $context->branchId)?->uuid : null,
        ];
    }
}
