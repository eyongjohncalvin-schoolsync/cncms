<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Tenant;
use App\Services\AreaSummaryService;
use App\Services\ArrearsAdjustmentService;
use App\Services\CashAlertService;
use App\Services\FieldInstallationService;
use App\Services\ManuscriptService;
use App\Services\PaymentService;
use App\Services\ResourcesDashboardService;
use App\Services\SetupGuideService;
use App\Services\SpendingControlService;
use App\Services\ZoneCoverageService;
use App\Services\ZonePerformanceService;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
        private readonly PaymentService $payments,
        private readonly ArrearsAdjustmentService $arrearsAdjustments,
        private readonly CashAlertService $cashAlerts,
        private readonly SpendingControlService $spending,
    ) {}

    public function index(Request $request): Response
    {
        $period = (string) $request->query('period', BusinessTimezone::currentPeriod());

        // The viewer's area — an agent's own zones — exactly as the phone's
        // Home shows it (AreaSummaryService, owner 2026-11-02).
        $zoneIds = TenantContext::currentZoneIds();
        $summary = $this->manuscripts->summary(['period' => $period] + ($zoneIds !== null ? ['zone_id' => $zoneIds] : []));
        $area = app(AreaSummaryService::class)->forViewer($period);
        $counts = ['total_customers' => $area['total_customers'], 'active_customers' => $area['active_customers']];
        // Each figure only for a role allowed to see it (owner, 2026-11-02).
        $see = fn (string $permission): bool => app(TenantContext::class)->can($permission);
        $money = $see('dashboard.money');

        return Inertia::render('Dashboard', [
            'period' => $period,
            'stats' => [
                'total_customers' => $see('customers.view') ? $counts['total_customers'] : 0,
                'active_customers' => $see('customers.view') ? $counts['active_customers'] : 0,
                // Cached separately (short 15s TTL) via PaymentService since
                // this figure changes far more often than the customer
                // counts above (new payments, verify/reject actions).
                'pending_verifications' => $see('payments.verify') ? $this->payments->pendingVerificationCount() : 0,
                // Subscription money only (collection rate's numerator);
                // installation fees are income too but shown separately
                // (docs/plans/installation-and-promotions.md). Technician
                // fees are never income.
                'monthly_income' => $money ? $summary['total_collected'] : '0',
                'installation_income' => $money ? app(ResourcesDashboardService::class)->installationIncome($period) : '0',
                // Kept apart for consistency and auditing (owner, 2026-10-26):
                // every verified bill payment this month, and the one-off fees.
                'income_by_purpose' => $money ? app(ResourcesDashboardService::class)->incomeByPurpose($period) : [],
                'total_arrears' => $money ? $summary['total_arrears'] : '0',
                'collection_rate' => $money ? $summary['collection_rate'] : 0,
                // Same "maker-checker pending count" idea as
                // pending_verifications above, for the Arrears Adjustment
                // feature (App\Services\ArrearsAdjustmentService::dashboard()
                // — previously computed but never actually surfaced
                // anywhere outside the Audit Log page's "Arrears
                // Adjustments" sub-tab, which meant there was no top-level
                // signal pointing anyone toward that review queue at all).
                // Only for those who approve (2026-11-02).
                'pending_arrears_adjustments' => $see('arrears.approve') ? $this->arrearsAdjustments->dashboard()['pending_approval'] : 0,
                // Customers registered in the field awaiting the office
                // (installation phase 3) — links to the activation queue.
                'pending_activations' => $see('installations.activate') ? app(FieldInstallationService::class)->pendingCount() : 0,
            ],
            // Cash controls (docs/plans/cash-controls.md): how many things
            // need a look on the Cash alerts page — only for those who can
            // open it (cash.view), and deferred so the dashboard never waits.
            'cash_alerts' => app(TenantContext::class)->can('cash.view')
                ? Inertia::defer(fn (): int => array_sum($this->cashAlerts->counts()))
                : null,
            // Zones nobody is accountable for (no active responsible collector)
            // — for whoever manages agents. Deferred like the cash alerts.
            'uncovered_zones' => app(TenantContext::class)->can('agents.manage')
                ? Inertia::defer(fn (): int => app(ZoneCoverageService::class)->uncoveredZoneCount())
                : null,
            // How the zones collect this month (owner, 2026-10-29): best,
            // worst and any well below usual — for whoever sees the cash
            // book. Deferred and cached (App\Services\ZonePerformanceService).
            'zone_performance' => app(TenantContext::class)->can('cash.view')
                ? Inertia::defer(fn (): array => app(ZonePerformanceService::class)->summary())
                : null,
            // Spending this month (docs/plans/expenditures-and-spending-controls.md,
            // items 5–6): deficit, cap use, budget warnings — for whoever sees
            // the P&L. Deferred like the cash alerts.
            // The new company's setup checklist (App\Services\SetupGuideService)
            // — super/admin only, gone once every step is done or hidden.
            'setup_guide' => $this->setupGuide(),
            'spending' => app(TenantContext::class)->can('expenditures.dashboard')
                ? Inertia::defer(function (): array {
                    $summary = $this->spending->summary(BusinessTimezone::currentPeriod());

                    return [
                        'period' => $summary['period'],
                        'spent' => $summary['spent'],
                        'income' => $summary['income'],
                        'net' => $summary['net'],
                        'deficit' => $summary['deficit'],
                        'cap' => $summary['cap'],
                        'cap_pct' => $summary['cap_pct'],
                        'budget_warnings' => $summary['budget_warnings'],
                    ];
                })
                : null,
        ]);
    }

    /**
     * "Hide this guide" on the setup checklist — for the whole company.
     */
    public function hideSetupGuide(): RedirectResponse
    {
        abort_unless($this->isAdmin(), 403);

        app(SetupGuideService::class)->hide($this->tenant());

        return back();
    }

    /**
     * @return array{steps: list<array{key: string, done: bool, href: string}>, phone: string, whatsapp: string, app_url: string|null}|null
     */
    private function setupGuide(): ?array
    {
        $steps = $this->isAdmin() ? app(SetupGuideService::class)->steps($this->tenant()) : null;

        return $steps === null ? null : [
            'steps' => $steps,
            'phone' => (string) config('site.phone'),
            'whatsapp' => (string) config('site.whatsapp'),
            'app_url' => config('agent-app.android_url') ?: null,
        ];
    }

    private function isAdmin(): bool
    {
        $context = app(TenantContext::class);

        return $context->can('company.update');
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return tenant();
    }
}
