<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\ArrearsAdjustmentService;
use App\Services\CashAlertService;
use App\Services\FieldInstallationService;
use App\Services\ManuscriptService;
use App\Services\PaymentService;
use App\Services\ResourcesDashboardService;
use App\Services\SpendingControlService;
use App\Services\ZoneCoverageService;
use App\Services\ZonePerformanceService;
use App\Support\BusinessTimezone;
use App\Support\CacheVersion;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        $summary = $this->manuscripts->summary(['period' => $period]);

        $branchId = TenantContext::currentBranchId();

        $counts = Cache::remember(
            'dashboard:counts:'.CacheVersion::of(CacheVersion::CUSTOMERS, CacheVersion::PAYMENTS).':'.($branchId ?? 'all'),
            now()->addSeconds(60),
            fn (): array => [
                'total_customers' => Customer::query()
                    ->when($branchId !== null, fn ($query) => $query->where('customers.branch_id', $branchId))
                    ->count(),
                'active_customers' => Customer::query()
                    ->where('status', 'active')
                    ->when($branchId !== null, fn ($query) => $query->where('customers.branch_id', $branchId))
                    ->count(),
            ],
        );

        return Inertia::render('Dashboard', [
            'period' => $period,
            'stats' => [
                'total_customers' => $counts['total_customers'],
                'active_customers' => $counts['active_customers'],
                // Cached separately (short 15s TTL) via PaymentService since
                // this figure changes far more often than the customer
                // counts above (new payments, verify/reject actions).
                'pending_verifications' => $this->payments->pendingVerificationCount(),
                // Subscription money only (collection rate's numerator);
                // installation fees are income too but shown separately
                // (docs/plans/installation-and-promotions.md). Technician
                // fees are never income.
                'monthly_income' => $summary['total_collected'],
                'installation_income' => app(ResourcesDashboardService::class)->installationIncome($period),
                // Kept apart for consistency and auditing (owner, 2026-10-26):
                // every verified bill payment this month, and the one-off fees.
                'income_by_purpose' => app(ResourcesDashboardService::class)->incomeByPurpose($period),
                'total_arrears' => $summary['total_arrears'],
                'collection_rate' => $summary['collection_rate'],
                // Same "maker-checker pending count" idea as
                // pending_verifications above, for the Arrears Adjustment
                // feature (App\Services\ArrearsAdjustmentService::dashboard()
                // — previously computed but never actually surfaced
                // anywhere outside the Audit Log page's "Arrears
                // Adjustments" sub-tab, which meant there was no top-level
                // signal pointing anyone toward that review queue at all).
                // Not role-gated, matching pending_verifications' existing
                // convention of showing the same raw count to every
                // dashboard viewer regardless of who can actually act on it.
                'pending_arrears_adjustments' => $this->arrearsAdjustments->dashboard()['pending_approval'],
                // Customers registered in the field awaiting the office
                // (installation phase 3) — links to the activation queue.
                'pending_activations' => app(FieldInstallationService::class)->pendingCount(),
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
                ? Inertia::defer(fn (): array => $this->zoneSummary())
                : null,
            // Spending this month (docs/plans/expenditures-and-spending-controls.md,
            // items 5–6): deficit, cap use, budget warnings — for whoever sees
            // the P&L. Deferred like the cash alerts.
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
     * The dashboard's zone card: the three best and three worst by the share
     * of billed customers who paid (one prepayment can't flatter it), and
     * every zone well below its usual.
     *
     * @return array{period: string, judged: bool, zones_count: int, best: list<array<string, mixed>>, worst: list<array<string, mixed>>, dropped: list<array<string, mixed>>}
     */
    private function zoneSummary(): array
    {
        $zonePerformance = app(ZonePerformanceService::class);
        $period = BusinessTimezone::currentPeriod();
        $ranked = collect($zonePerformance->forPeriod($period))
            ->filter(fn (array $zone): bool => $zone['paid_share'] !== null)
            ->sortByDesc(fn (array $zone): float => $zone['paid_share'])
            ->map(fn (array $zone): array => array_diff_key($zone, ['trend' => true]))
            ->values();

        return [
            'period' => $period,
            'judged' => BusinessTimezone::now()->day >= ZonePerformanceService::JUDGE_FROM_DAY,
            'zones_count' => $ranked->count(),
            'best' => $ranked->take(3)->values()->all(),
            // Never the same zone in both lists.
            'worst' => $ranked->slice(max(3, $ranked->count() - 3))->reverse()->values()->all(),
            'dropped' => collect($zonePerformance->dropped($period))->map(fn (array $zone): array => array_diff_key($zone, ['trend' => true]))->values()->all(),
        ];
    }
}
