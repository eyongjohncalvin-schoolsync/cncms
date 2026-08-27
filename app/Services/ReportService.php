<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\ArrearsAdjustment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Expenditure;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\Zone;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Builds the /reports Daily/Weekly/Monthly payloads — see the task spec
 * this was built from for the full content list. Follows
 * ResourcesDashboardService's established pattern: everything is computed
 * on demand from existing tables (payments, customers, expenditures,
 * audit_logs, command_runs, zones, agents), no snapshot table, no new
 * scheduled job.
 *
 * Branch/zone fencing: `payments`/`customers` have no direct branch
 * column — branch is only reachable via `payment -> customer -> zone ->
 * zones.branch_id`. Every query below joins `customers`/`zones` explicitly
 * (rather than the correlated-EXISTS `whereHas()` pattern
 * App\Repositories\Concerns\ScopesByBranch uses for row-listing queries) —
 * better index usage for the aggregate/GROUP BY queries this service is
 * built from. `agent`-role users are fenced one level tighter than their
 * TenantContext::branchId (which only resolves to their zone's *branch*):
 * agentZoneId() resolves their own Agent row's zone_id directly, and every
 * query here prefers that over the branch fence when it's set — see
 * fenceZones().
 *
 * Cache: tiered TTL by period state (in-progress / just-closed / sealed —
 * see ttlFor()), never rememberForever. Every cache key includes the
 * caller's branch (and, for agents, zone) fence — see scopeSuffix() — so a
 * cross-branch ('all') caller and a branch-fenced caller never share an
 * entry, matching the same rule already established for
 * ResourcesDashboardService/PaymentService/ManuscriptService this session.
 */
class ReportService
{
    private const CACHE_PREFIX = 'reports';

    public function __construct(
        private readonly ManuscriptService $manuscripts,
        private readonly ResourcesDashboardService $resourcesDashboard,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function daily(?string $date): array
    {
        $day = $this->resolveDate($date);
        [$start, $end] = $this->boundsUtc($day, $day);
        $branchId = $this->context->branchId;
        $zoneId = $this->agentZoneId();

        $key = sprintf('%s:daily:%s:%s', self::CACHE_PREFIX, $day->toDateString(), $this->scopeSuffix($branchId, $zoneId));

        return Cache::remember(
            $key,
            $this->ttlFor($start, $end),
            fn (): array => $this->buildDaily($day, $start, $end, $branchId, $zoneId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function weekly(?string $date): array
    {
        $day = $this->resolveDate($date);
        $weekStart = $day->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $day->copy()->endOfWeek(Carbon::SUNDAY);
        [$start, $end] = $this->boundsUtc($weekStart, $weekEnd);
        $branchId = $this->context->branchId;
        $zoneId = $this->agentZoneId();

        $key = sprintf('%s:weekly:%s:%s', self::CACHE_PREFIX, $this->weekId($weekStart), $this->scopeSuffix($branchId, $zoneId));

        return Cache::remember(
            $key,
            $this->ttlFor($start, $end),
            fn (): array => $this->buildWeekly($weekStart, $weekEnd, $start, $end, $branchId, $zoneId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function monthly(?string $period): array
    {
        $period = $this->resolvePeriod($period);
        [$start, $end] = $this->monthBoundsUtc($period);
        $branchId = $this->context->branchId;
        $zoneId = $this->agentZoneId();

        $key = sprintf('%s:monthly:%s:%s', self::CACHE_PREFIX, $period, $this->scopeSuffix($branchId, $zoneId));

        // The cached payload is role-agnostic (branch/zone-fenced only) —
        // buildMonthly() always computes the pnl block. Role-based
        // visibility (pnl gated OUT for manager/agent) is applied AFTER
        // every retrieval, cached or fresh, via applyRoleVisibility()
        // below. Baking the role-filtered shape into the cache key/value
        // itself would let one role's cached response leak into another
        // role's payload whenever they share the same branch scope (e.g. a
        // super and a manager both unrestricted/'all') — exactly the kind
        // of cross-role data leak this feature must not introduce.
        $payload = Cache::remember(
            $key,
            $this->ttlFor($start, $end),
            fn (): array => $this->buildMonthly($period, $start, $end, $branchId, $zoneId),
        );

        return $this->applyRoleVisibility($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyRoleVisibility(array $payload): array
    {
        if (! $this->context->isAnyOf('super', 'admin')) {
            unset($payload['pnl']);
        }

        return $payload;
    }

    /**
     * Monthly-tier PDF export data — computed FRESH, bypassing the cache
     * entirely (same convention as ManuscriptService::exportData() bypassing
     * summary() via summaryFor()), so a just-run manuscript:calculate or a
     * just-verified payment is never hidden behind a stale cache entry on
     * the one document someone is about to hand to an owner/auditor.
     *
     * @return array<string, mixed>
     */
    public function exportMonthly(?string $period): array
    {
        $period = $this->resolvePeriod($period);
        [$start, $end] = $this->monthBoundsUtc($period);
        $branchId = $this->context->branchId;
        $zoneId = $this->agentZoneId();

        $data = $this->applyRoleVisibility($this->buildMonthly($period, $start, $end, $branchId, $zoneId));
        $data['branch_label'] = $this->branchLabel($branchId, $zoneId);
        $data['generated_at'] = Carbon::now(BusinessTimezone::WAT);

        return $data;
    }

    /**
     * Best-effort cache invalidation for a write that lands in the period
     * containing $dateWat — called from ExpenditureService (spent_at writes)
     * and PaymentVerificationService (verify/reject writes), extending the
     * exact "forget own key + forget 'all' key" tradeoff already documented
     * on App\Services\CustomerService::forgetShowCache(): only the
     * unrestricted ('all') key and the given $branchId's key are forgotten,
     * never every possible zone-fenced agent's copy — those are left to
     * expire via ttlFor()'s TTL instead. Static (no DI) so write-side
     * services can call it without taking on a ReportService/TenantContext
     * dependency of their own — this method touches no query, nothing here
     * needs $this.
     */
    public static function forgetCache(Carbon $dateWat, ?int $branchId = null): void
    {
        $day = $dateWat->copy()->startOfDay();
        $weekStart = $day->copy()->startOfWeek(Carbon::MONDAY);
        $period = $day->format('Y-m');
        $weekId = $weekStart->format('Y').'-W'.str_pad((string) $weekStart->isoWeek(), 2, '0', STR_PAD_LEFT);

        foreach (array_unique([$branchId, null], SORT_REGULAR) as $scopeBranchId) {
            $suffix = (string) ($scopeBranchId ?? 'all');
            Cache::forget(self::CACHE_PREFIX.":daily:{$day->toDateString()}:{$suffix}");
            Cache::forget(self::CACHE_PREFIX.":weekly:{$weekId}:{$suffix}");
            Cache::forget(self::CACHE_PREFIX.":monthly:{$period}:{$suffix}");
        }
    }

    // -----------------------------------------------------------------
    // Daily
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildDaily(Carbon $day, Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        return [
            'tier' => 'daily',
            'date' => $day->toDateString(),
            'label' => $day->format('D j M Y'),
            'is_current' => $day->isSameDay(Carbon::now(BusinessTimezone::WAT)),
            'payments' => $this->paymentsBreakdown($start, $end, $branchId, $zoneId),
            // The flat table of today's payments the Daily tier's UI shows
            // for office roles (super/admin/manager) — see the task spec's
            // "Daily: stat cards only ... + one flat table of today's
            // payments". Capped defensively; a single WAT calendar day's
            // payment volume never approaches this in practice.
            'payments_today' => $this->paymentsListForDay($start, $end, $branchId, $zoneId),
            'pending_queue' => $this->pendingQueue($branchId, $zoneId),
            'verifications_actioned' => $this->verificationsActioned($start, $end, $branchId, $zoneId),
            'new_customers' => ['count' => $this->newCustomersCount($start, $end, $branchId, $zoneId)],
            'status_changes' => $this->statusChanges($start, $end, $branchId, $zoneId),
            // Not branch/zone-scoped — see ResourcesDashboardService::expensesFor()'s
            // doc comment: expenditures carry no zone/branch attribution in
            // the schema today.
            'expenditures' => $this->expendituresForDay($day),
            'offline_sync' => $this->offlineSync($start, $end, $branchId, $zoneId),
        ];
    }

    /**
     * @return array{count: int, total: string}
     */
    private function expendituresForDay(Carbon $day): array
    {
        $row = Expenditure::query()
            ->whereDate('spent_at', $day->toDateString())
            ->selectRaw('count(*) as count, coalesce(sum(amount), 0) as total')
            ->first();

        return [
            'count' => (int) $row->count,
            'total' => (string) $row->total,
        ];
    }

    /**
     * @return array{count: int, total: string, by_device: list<array{device: ?string, count: int, total: string}>}
     */
    private function offlineSync(Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        $totals = $this->paymentsQuery($branchId, $zoneId)
            ->where('payments.recorded_offline', true)
            ->whereBetween('payments.created_at', [$start, $end])
            ->selectRaw('count(*) as count, coalesce(sum(payments.amount), 0) as total')
            ->first();

        // payments has no recorder column (only recorded_by_device) — true
        // per-agent attribution isn't possible with the current schema. A
        // future payments.recorded_by column would enable a real per-agent
        // breakdown; until then this groups by device, which is what's
        // actually recorded.
        $byDevice = $this->paymentsQuery($branchId, $zoneId)
            ->where('payments.recorded_offline', true)
            ->whereBetween('payments.created_at', [$start, $end])
            ->groupBy('payments.recorded_by_device')
            ->selectRaw('payments.recorded_by_device as device, count(*) as count, coalesce(sum(payments.amount), 0) as total')
            ->get()
            ->map(fn ($row): array => [
                'device' => $row->device,
                'count' => (int) $row->count,
                'total' => (string) $row->total,
            ])
            ->values()
            ->all();

        return [
            'count' => (int) $totals->count,
            'total' => (string) $totals->total,
            'by_device' => $byDevice,
        ];
    }

    // -----------------------------------------------------------------
    // Weekly
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildWeekly(Carbon $weekStart, Carbon $weekEnd, Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        $current = $this->weeklyRollup($start, $end, $branchId, $zoneId);

        $prevWeekStart = $weekStart->copy()->subWeek();
        $prevWeekEnd = $weekEnd->copy()->subWeek();
        [$prevStart, $prevEnd] = $this->boundsUtc($prevWeekStart, $prevWeekEnd);
        $previous = $this->weeklyRollup($prevStart, $prevEnd, $branchId, $zoneId);

        return [
            'tier' => 'weekly',
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            // Explicit dates, never a bare week number — per the task spec.
            'label' => 'Week '.$weekStart->isoWeek().' · '.$weekStart->format('M j').'–'.$weekEnd->format('j'),
            'is_current' => Carbon::now(BusinessTimezone::WAT)->between($weekStart, $weekEnd),
            'collections' => $current['collections'],
            // The Weekly tier's exactly-one 7-bar BarChart — reuses the same
            // day-bucketing query the Monthly tier's trend line uses
            // (dailyTrend() is range-agnostic), just bounded to this week.
            'daily_breakdown' => $this->dailyTrend($start, $end, $branchId, $zoneId),
            'new_customers' => $current['new_customers'],
            'net_disconnections' => $current['net_disconnections'],
            'deltas' => [
                'collections_total' => $this->delta($current['collections']['total'], $previous['collections']['total']),
                'payment_count' => $this->delta($current['collections']['count'], $previous['collections']['count']),
                'new_customers' => $this->delta($current['new_customers'], $previous['new_customers']),
                'net_disconnections' => $this->delta($current['net_disconnections'], $previous['net_disconnections']),
            ],
            'league_table' => $this->leagueTable($start, $end, $branchId, $zoneId),
            // The figure that most directly protects revenue — verified
            // payments are the only ones manuscript:calculate ever counts
            // (App\Console\Commands\ManuscriptCalculate reads only
            // verification_status = 'verified' payments), so a payment
            // stuck pending for a week or more is a week of billed revenue
            // that isn't actually landing anywhere yet.
            'verification_sla' => $this->verificationSla($branchId, $zoneId),
        ];
    }

    /**
     * @return array{collections: array{total: string, verified: string, count: int}, new_customers: int, net_disconnections: int}
     */
    private function weeklyRollup(Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        $paymentsRow = $this->paymentsQuery($branchId, $zoneId)
            ->whereBetween('payments.created_at', [$start, $end])
            ->selectRaw("
                coalesce(sum(payments.amount) filter (where payments.verification_status = 'verified'), 0) as verified,
                coalesce(sum(payments.amount), 0) as total,
                count(*) as payment_count
            ")
            ->first();

        $newCustomers = $this->newCustomersCount($start, $end, $branchId, $zoneId);

        $statusChanges = $this->statusChanges($start, $end, $branchId, $zoneId);
        $disconnects = (int) collect($statusChanges)->where('to', 'disconnected')->sum('count');
        $reconnects = (int) collect($statusChanges)
            ->where('from', 'disconnected')
            ->whereIn('to', ['active', 'passive'])
            ->sum('count');

        return [
            'collections' => [
                'total' => (string) $paymentsRow->total,
                'verified' => (string) $paymentsRow->verified,
                'count' => (int) $paymentsRow->payment_count,
            ],
            'new_customers' => $newCustomers,
            'net_disconnections' => $disconnects - $reconnects,
        ];
    }

    /**
     * Collected ÷ expected ratio per zone, normalized so it ranks
     * collection performance rather than just headcount. `expected` is
     * sum(customers.bill) for active customers in that zone — not the same
     * as manuscripts.total_bill (which includes carried-over arrears); this
     * is deliberately just the zone's raw monthly billing capacity.
     *
     * Agents (zoneId !== null) never see this — it's a cross-zone
     * comparison and an agent is fenced to exactly one zone already.
     * Managers (branchId !== null, zoneId null) see only their own
     * branch's zones. super/admin (both null) see every zone.
     *
     * @return list<array{zone_uuid: string, zone_name: string, collected: string, expected: string, ratio_pct: float}>
     */
    private function leagueTable(Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        if ($zoneId !== null) {
            return [];
        }

        $zones = Zone::query()
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->get(['id', 'uuid', 'name']);

        if ($zones->isEmpty()) {
            return [];
        }

        $collectedByZone = Payment::query()
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->where('payments.verification_status', 'verified')
            ->whereBetween('payments.created_at', [$start, $end])
            ->whereIn('customers.zone_id', $zones->pluck('id'))
            ->groupBy('customers.zone_id')
            ->selectRaw('customers.zone_id as zone_id, coalesce(sum(payments.amount), 0) as collected')
            ->pluck('collected', 'zone_id');

        $expectedByZone = Customer::query()
            ->where('status', 'active')
            ->whereIn('zone_id', $zones->pluck('id'))
            ->groupBy('zone_id')
            ->selectRaw('zone_id, coalesce(sum(bill), 0) as expected')
            ->pluck('expected', 'zone_id');

        return $zones->map(function (Zone $zone) use ($collectedByZone, $expectedByZone): array {
            $collected = (string) ($collectedByZone[$zone->id] ?? '0.00');
            $expected = (string) ($expectedByZone[$zone->id] ?? '0.00');

            $ratio = bccomp($expected, '0.00', 2) > 0
                ? round(((float) $collected / (float) $expected) * 100, 1)
                : 0.0;

            return [
                'zone_uuid' => $zone->uuid,
                'zone_name' => $zone->name,
                'collected' => $collected,
                'expected' => $expected,
                'ratio_pct' => $ratio,
            ];
        })->sortByDesc('ratio_pct')->values()->all();
    }

    /**
     * Pending payments older than 7 days, grouped by branch.
     *
     * @return list<array{branch_name: string, count: int, total: string}>
     */
    private function verificationSla(?int $branchId, ?int $zoneId): array
    {
        $cutoff = Carbon::now()->subDays(7);

        $query = Payment::query()
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->join('zones', 'zones.id', '=', 'customers.zone_id')
            ->join('branches', 'branches.id', '=', 'zones.branch_id')
            ->where('payments.verification_status', 'pending')
            ->where('payments.created_at', '<', $cutoff);

        $query = $this->fenceZones($query, $branchId, $zoneId);

        return $query
            ->groupBy('branches.id', 'branches.name')
            ->selectRaw('branches.name as branch_name, count(*) as count, coalesce(sum(payments.amount), 0) as total')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row): array => [
                'branch_name' => (string) $row->branch_name,
                'count' => (int) $row->count,
                'total' => (string) $row->total,
            ])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Monthly
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildMonthly(string $period, Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        $payload = [
            'tier' => 'monthly',
            'period' => $period,
            'label' => Carbon::createFromFormat('Y-m', $period)->format('F Y'),
            'is_current' => $period === Carbon::now(BusinessTimezone::WAT)->format('Y-m'),
            // Deliberately distinct key/heading from the billing/P&L blocks
            // below — see this method's class doc and the task spec's
            // "Critical labeling requirement": this total is bucketed by
            // payments.created_at across ALL verification statuses, while
            // billing_ledger/pnl below are verified-only and bucketed by
            // manuscript processing. They will legitimately disagree; never
            // merge them under one heading.
            'collections_cash_received' => $this->paymentsBreakdown($start, $end, $branchId, $zoneId),
            // Arrears Adjustment feature: approved, 'decrease'-direction
            // (write-off) adjustments TARGETING this period — deliberately
            // its own top-level key, never merged into collections_cash_received
            // or billing_ledger above, matching this method's existing
            // "distinct key/heading, they will legitimately disagree" rule
            // for exactly the same reason (this is a ledger correction, not
            // cash collected or a normal billing run).
            'arrears_adjustments_written_off' => $this->arrearsAdjustmentsWrittenOff($period, $branchId, $zoneId),
            'billing_ledger' => $this->billingRunFor($period),
            'collection_health' => $this->collectionHealth($period, $branchId, $zoneId),
            'trend' => $this->dailyTrend($start, $end, $branchId, $zoneId),
            // Always computed here (this method's result is the CACHED,
            // role-agnostic payload) — P&L is gated OUT for manager/agent
            // per the task spec's role table, but that filtering happens in
            // monthly()/exportMonthly()'s applyRoleVisibility(), applied
            // fresh to every caller after the cache lookup. See monthly()'s
            // doc comment for why baking the role decision into the cached
            // value itself would leak one role's payload into another's.
            'pnl' => $this->resourcesDashboard->dashboard($period),
        ];

        return $payload;
    }

    /**
     * @return ?array{ran_at: ?string, customers_processed: ?int, frozen_customers: ?int, total_bill_sum: ?string, total_arrears_sum: ?string, total_credit_sum: ?string, errors: ?int, error_details: array<int, mixed>, duration_ms: ?float}
     */
    private function billingRunFor(string $period): ?array
    {
        $run = CommandRun::query()
            ->where('command', 'manuscript:calculate')
            ->where('period', $period)
            ->orderByDesc('ran_at')
            ->first();

        // Renders an explicit "Billing run not yet executed for this
        // period" empty state on the frontend rather than zeros — a run
        // that hasn't happened yet is a categorically different situation
        // from one that ran and processed nothing.
        if ($run === null) {
            return null;
        }

        $metadata = $run->metadata ?? [];

        return [
            'ran_at' => $run->ran_at?->toIso8601String(),
            'customers_processed' => isset($metadata['customers_processed']) ? (int) $metadata['customers_processed'] : null,
            'frozen_customers' => isset($metadata['frozen_customers']) ? (int) $metadata['frozen_customers'] : null,
            'total_bill_sum' => isset($metadata['total_bill_sum']) ? (string) $metadata['total_bill_sum'] : null,
            'total_arrears_sum' => isset($metadata['total_arrears_sum']) ? (string) $metadata['total_arrears_sum'] : null,
            'total_credit_sum' => isset($metadata['total_credit_sum']) ? (string) $metadata['total_credit_sum'] : null,
            'errors' => isset($metadata['errors']) ? (int) $metadata['errors'] : null,
            'error_details' => $metadata['error_details'] ?? [],
            'duration_ms' => isset($metadata['duration_ms']) ? (float) $metadata['duration_ms'] : null,
        ];
    }

    /**
     * @return array{collection_rate: float, total_collected: string, total_bill: string, arrears_aging: array{"1x": int, "2x": int, "3x_plus": int}}
     */
    private function collectionHealth(string $period, ?int $branchId, ?int $zoneId): array
    {
        // ManuscriptService::summary() already branch-fences itself via
        // TenantContext::currentBranchId() internally (see
        // ManuscriptRepository::scoped()); the extra 'zone_id' filter here
        // narrows it further for an agent (whose TenantContext::branchId
        // only resolves to their zone's *branch*, not the zone itself).
        $filters = ['period' => $period];
        if ($zoneId !== null) {
            $filters['zone_id'] = $zoneId;
        }

        $summary = $this->manuscripts->summary($filters);

        return [
            'collection_rate' => $summary['collection_rate'],
            'total_collected' => $summary['total_collected'],
            'total_bill' => $summary['total_bill'],
            'arrears_aging' => $this->arrearsAging($period, $branchId, $zoneId),
        ];
    }

    /**
     * Buckets active customers with a manuscript for $period by how many
     * multiples of their own monthly bill their total_arrears represents —
     * 1x, 2x, or 3x+ (using CustomerEligibilityService::THRESHOLD_MULTIPLIER
     * so this can never drift from the arrears-eligibility board's own
     * threshold). Customers under 1x arrears aren't counted in any bucket —
     * this is an aging view of customers already behind, not a full
     * distribution.
     *
     * @return array{"1x": int, "2x": int, "3x_plus": int}
     */
    private function arrearsAging(string $period, ?int $branchId, ?int $zoneId): array
    {
        $query = Manuscript::query()
            ->join('customers', 'customers.id', '=', 'manuscripts.customer_id')
            ->join('zones', 'zones.id', '=', 'customers.zone_id')
            ->where('manuscripts.period', $period)
            ->where('customers.status', 'active');

        $query = $this->fenceZones($query, $branchId, $zoneId);

        $rows = $query->select('manuscripts.total_arrears', 'customers.bill')->get();

        $oneX = 0;
        $twoX = 0;
        $threeXPlus = 0;

        foreach ($rows as $row) {
            $bill = bcadd((string) $row->bill, '0.00', 2);

            if (bccomp($bill, '0.00', 2) <= 0) {
                continue;
            }

            $arrears = bcadd((string) $row->total_arrears, '0.00', 2);

            if (bccomp($arrears, $bill, 2) < 0) {
                continue;
            }

            $threshold3x = bcmul($bill, CustomerEligibilityService::THRESHOLD_MULTIPLIER, 2);
            $threshold2x = bcmul($bill, '2', 2);

            if (bccomp($arrears, $threshold3x, 2) >= 0) {
                $threeXPlus++;
            } elseif (bccomp($arrears, $threshold2x, 2) >= 0) {
                $twoX++;
            } else {
                $oneX++;
            }
        }

        return ['1x' => $oneX, '2x' => $twoX, '3x_plus' => $threeXPlus];
    }

    /**
     * Day-by-day verified-collections trend across the month, in WAT wall
     * clock days — feeds the monthly tier's LineChart/AreaChart.
     *
     * @return list<array{date: string, verified: string, payment_count: int}>
     */
    private function dailyTrend(Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        $rows = $this->paymentsQuery($branchId, $zoneId)
            ->whereBetween('payments.created_at', [$start, $end])
            ->selectRaw(
                "(payments.created_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date as day, ".
                "coalesce(sum(payments.amount) filter (where payments.verification_status = 'verified'), 0) as verified, ".
                'count(*) as payment_count',
                [BusinessTimezone::WAT]
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $rows->map(fn ($row): array => [
            'date' => (string) $row->day,
            'verified' => (string) $row->verified,
            'payment_count' => (int) $row->payment_count,
        ])->values()->all();
    }

    // -----------------------------------------------------------------
    // Shared query building blocks
    // -----------------------------------------------------------------

    /**
     * @return array{total: string, verified: string, pending: string, rejected: string, count: int}
     */
    private function paymentsBreakdown(Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        $row = $this->paymentsQuery($branchId, $zoneId)
            ->whereBetween('payments.created_at', [$start, $end])
            ->selectRaw("
                coalesce(sum(payments.amount) filter (where payments.verification_status = 'verified'), 0) as verified,
                coalesce(sum(payments.amount) filter (where payments.verification_status = 'pending'), 0) as pending,
                coalesce(sum(payments.amount) filter (where payments.verification_status = 'rejected'), 0) as rejected,
                count(*) as payment_count
            ")
            ->first();

        $verified = (string) $row->verified;
        $pending = (string) $row->pending;
        $rejected = (string) $row->rejected;

        return [
            'total' => bcadd(bcadd($verified, $pending, 2), $rejected, 2),
            'verified' => $verified,
            'pending' => $pending,
            'rejected' => $rejected,
            'count' => (int) $row->payment_count,
        ];
    }

    /**
     * All-time pending-verification backlog (not scoped to the requested
     * period) — count, FCFA total, the age of the oldest pending payment,
     * and (capped) the oldest few payments themselves — this doubles as the
     * agent view's "customers outstanding" list (see the task spec's agent
     * layout) since a customer whose payment is stuck pending is,
     * operationally, still outstanding.
     *
     * @return array{count: int, total: string, oldest_age_hours: ?int, oldest_created_at: ?string, items: list<array{uuid: string, customer_name: string, amount: string, age_hours: int}>}
     */
    private function pendingQueue(?int $branchId, ?int $zoneId): array
    {
        $row = $this->paymentsQuery($branchId, $zoneId)
            ->where('payments.verification_status', 'pending')
            ->selectRaw('count(*) as count, coalesce(sum(payments.amount), 0) as total, min(payments.created_at) as oldest')
            ->first();

        $oldest = $row->oldest ? Carbon::parse($row->oldest) : null;

        return [
            'count' => (int) $row->count,
            'total' => (string) $row->total,
            'oldest_age_hours' => $oldest ? (int) $oldest->diffInHours(Carbon::now()) : null,
            'oldest_created_at' => $oldest?->toIso8601String(),
            'items' => $this->pendingQueueItems($branchId, $zoneId),
        ];
    }

    /**
     * @return list<array{uuid: string, customer_name: string, amount: string, age_hours: int}>
     */
    private function pendingQueueItems(?int $branchId, ?int $zoneId, int $limit = 10): array
    {
        return $this->paymentsQuery($branchId, $zoneId)
            ->where('payments.verification_status', 'pending')
            ->orderBy('payments.created_at')
            ->limit($limit)
            ->get([
                'payments.uuid as uuid',
                'payments.amount as amount',
                'payments.created_at as created_at',
                'customers.name as customer_name',
            ])
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'customer_name' => (string) $row->customer_name,
                'amount' => (string) $row->amount,
                'age_hours' => (int) Carbon::parse($row->created_at)->diffInHours(Carbon::now()),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{uuid: string, customer_name: string, zone_name: string, amount: string, verification_status: string, created_at: string}>
     */
    private function paymentsListForDay(Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId, int $limit = 200): array
    {
        return $this->paymentsQuery($branchId, $zoneId)
            ->whereBetween('payments.created_at', [$start, $end])
            ->orderByDesc('payments.created_at')
            ->limit($limit)
            ->get([
                'payments.uuid as uuid',
                'payments.amount as amount',
                'payments.verification_status as verification_status',
                'payments.created_at as created_at',
                'customers.name as customer_name',
                'zones.name as zone_name',
            ])
            ->map(fn ($row): array => [
                'uuid' => (string) $row->uuid,
                'customer_name' => (string) $row->customer_name,
                'zone_name' => (string) $row->zone_name,
                'amount' => (string) $row->amount,
                'verification_status' => (string) $row->verification_status,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{approved: int, rejected: int}
     */
    private function verificationsActioned(Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        $query = PaymentVerification::query()
            ->join('payments', 'payments.id', '=', 'payment_verifications.payment_id')
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->join('zones', 'zones.id', '=', 'customers.zone_id')
            ->whereNotNull('payment_verifications.verified_at')
            ->whereBetween('payment_verifications.verified_at', [$start, $end]);

        $query = $this->fenceZones($query, $branchId, $zoneId);

        $row = $query->selectRaw("
            count(*) filter (where payment_verifications.status = 'approved') as approved,
            count(*) filter (where payment_verifications.status = 'rejected') as rejected
        ")->first();

        return [
            'approved' => (int) $row->approved,
            'rejected' => (int) $row->rejected,
        ];
    }

    private function newCustomersCount(Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): int
    {
        return (int) $this->customersQuery($branchId, $zoneId)
            ->whereBetween('customers.created_at', [$start, $end])
            ->count('customers.id');
    }

    /**
     * Status changes (disconnect/suspend/reconnect) read from audit_logs —
     * there is no dedicated status-change table. AuditableObserver writes
     * `record_id` as the mutated model's internal id, so joining
     * audit_logs.record_id = customers.id (for table_name = 'customers'
     * rows) recovers the customer, and therefore the zone/branch, that
     * changed.
     *
     * @return list<array{from: ?string, to: ?string, count: int}>
     */
    private function statusChanges(Carbon $start, Carbon $end, ?int $branchId, ?int $zoneId): array
    {
        $query = AuditLog::query()
            ->join('customers', 'customers.id', '=', 'audit_logs.record_id')
            ->join('zones', 'zones.id', '=', 'customers.zone_id')
            ->where('audit_logs.table_name', 'customers')
            ->where('audit_logs.action', 'update')
            ->whereBetween('audit_logs.created_at', [$start, $end])
            ->whereRaw("audit_logs.old_values->>'status' IS DISTINCT FROM audit_logs.new_values->>'status'");

        $query = $this->fenceZones($query, $branchId, $zoneId);

        return $query
            ->selectRaw("audit_logs.old_values->>'status' as from_status, audit_logs.new_values->>'status' as to_status, count(*) as count")
            ->groupBy('from_status', 'to_status')
            ->get()
            ->map(fn ($row): array => [
                'from' => $row->from_status,
                'to' => $row->to_status,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    private function paymentsQuery(?int $branchId, ?int $zoneId): Builder
    {
        $query = Payment::query()
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->join('zones', 'zones.id', '=', 'customers.zone_id');

        return $this->fenceZones($query, $branchId, $zoneId);
    }

    private function customersQuery(?int $branchId, ?int $zoneId): Builder
    {
        $query = Customer::query()->join('zones', 'zones.id', '=', 'customers.zone_id');

        return $this->fenceZones($query, $branchId, $zoneId);
    }

    /**
     * Approved, 'decrease'-direction arrears adjustments TARGETING $period —
     * the "written off" figure for the monthly report's StatCard and PDF
     * export (Arrears Adjustment feature). Deliberately excludes 'increase'
     * adjustments (a billing-error correction the other way is not a
     * write-off) and anything still pending/rejected (zero ledger effect —
     * see App\Services\ArrearsAdjustmentService::reject()'s doc comment).
     * Bucketed by `target_period`, not `approved_at`/`processed_period` —
     * this is "how much of THIS period's arrears got written off," matching
     * how billing_ledger above is also keyed by the period being corrected,
     * not the period the correction happened to be approved in.
     *
     * @return array{count: int, total: string}
     */
    private function arrearsAdjustmentsWrittenOff(string $period, ?int $branchId, ?int $zoneId): array
    {
        $query = ArrearsAdjustment::query()
            ->join('customers', 'customers.id', '=', 'arrears_adjustments.customer_id')
            ->join('zones', 'zones.id', '=', 'customers.zone_id')
            ->where('arrears_adjustments.status', 'approved')
            ->where('arrears_adjustments.direction', 'decrease')
            ->where('arrears_adjustments.target_period', $period);

        $row = $this->fenceZones($query, $branchId, $zoneId)
            ->selectRaw('count(*) as count, coalesce(sum(arrears_adjustments.amount), 0) as total')
            ->first();

        return [
            'count' => (int) $row->count,
            'total' => (string) $row->total,
        ];
    }

    /**
     * Explicit JOIN-based branch/zone fencing for report aggregate queries
     * (preferred over App\Repositories\Concerns\ScopesByBranch's correlated
     * `whereHas()` for these — better index usage on GROUP BY/aggregate
     * queries). $query must already join a `zones` table. A zone fence
     * (agent role) always wins over a branch fence when both are present —
     * a zone is strictly narrower than its branch.
     */
    private function fenceZones(Builder $query, ?int $branchId, ?int $zoneId): Builder
    {
        if ($zoneId !== null) {
            return $query->where('zones.id', $zoneId);
        }

        if ($branchId !== null) {
            return $query->where('zones.branch_id', $branchId);
        }

        return $query;
    }

    /**
     * An `agent`-role user's report fence is their own zone specifically —
     * one level tighter than TenantContext::branchId (which, for agents,
     * already resolves to their zone's *branch* — see
     * TenantContext::resolveAgentBranchId()). Mirrors that exact same
     * "resolve this agent's own Agent row's zone" pattern rather than
     * duplicating it differently here. Returns null for every other role
     * (their fence is branchId alone, handled by fenceZones()).
     */
    private function agentZoneId(): ?int
    {
        if ($this->context->role !== 'agent') {
            return null;
        }

        $agent = Agent::query()->where('user_id', $this->context->tenantUser->user_id)->first();

        return $agent?->zone_id;
    }

    private function branchLabel(?int $branchId, ?int $zoneId): string
    {
        if ($zoneId !== null) {
            $zone = Zone::find($zoneId);

            return $zone ? "Zone: {$zone->name}" : 'Zone-scoped';
        }

        if ($branchId !== null) {
            $branch = Branch::find($branchId);

            return $branch?->name ?? 'Branch-scoped';
        }

        return 'All Branches';
    }

    private function scopeSuffix(?int $branchId, ?int $zoneId): string
    {
        $suffix = (string) ($branchId ?? 'all');

        if ($zoneId !== null) {
            $suffix .= ':zone-'.$zoneId;
        }

        return $suffix;
    }

    /**
     * Tiered cache TTL by period state — never rememberForever:
     *   - in-progress (now falls inside [start, end])   -> 60s
     *   - just-closed (< 48h since period end)           -> 10 minutes
     *   - sealed (>= 48h since period end)                -> 24h
     *
     * A period whose start is still in the future (a defensive edge case —
     * the frontend disables navigating past the current period, but a
     * direct query-string request could still ask for one) is treated the
     * same as in-progress: cheap, short-lived caching is the safe direction
     * for data that shouldn't exist yet.
     */
    private function ttlFor(Carbon $periodStartUtc, Carbon $periodEndUtc): Carbon
    {
        $now = Carbon::now();

        if ($now->lt($periodEndUtc)) {
            return now()->addSeconds(60);
        }

        if ($periodEndUtc->diffInHours($now) < 48) {
            return now()->addMinutes(10);
        }

        return now()->addHours(24);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function boundsUtc(Carbon $startWat, Carbon $endWat): array
    {
        return [
            $startWat->copy()->startOfDay()->setTimezone('UTC'),
            $endWat->copy()->endOfDay()->setTimezone('UTC'),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthBoundsUtc(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period, BusinessTimezone::WAT)->startOfMonth()->setTimezone('UTC');
        $end = Carbon::createFromFormat('Y-m', $period, BusinessTimezone::WAT)->endOfMonth()->setTimezone('UTC');

        return [$start, $end];
    }

    private function weekId(Carbon $weekStart): string
    {
        return $weekStart->format('Y').'-W'.str_pad((string) $weekStart->isoWeek(), 2, '0', STR_PAD_LEFT);
    }

    private function resolveDate(?string $date): Carbon
    {
        if ($date === null || $date === '') {
            return Carbon::now(BusinessTimezone::WAT)->startOfDay();
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages(['date' => ['The date must be in YYYY-MM-DD format.']]);
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $date, BusinessTimezone::WAT)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => ['The date must be a valid calendar date.']]);
        }
    }

    private function resolvePeriod(?string $period): string
    {
        $period ??= Carbon::now(BusinessTimezone::WAT)->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw ValidationException::withMessages(['period' => ['The period must be in YYYY-MM format.']]);
        }

        return $period;
    }

    /**
     * @return ?array{pct: float, direction: 'up'|'down'|'flat'}
     */
    private function delta(string|int|float $current, string|int|float $previous): ?array
    {
        $prev = (float) $previous;

        // No prior-period data to compare against — omit rather than show
        // a fabricated +100%/undefined percentage. Frontend renders a plain
        // "—" when this is null.
        if ($prev === 0.0) {
            return null;
        }

        $curr = (float) $current;
        $pct = round((($curr - $prev) / abs($prev)) * 100, 1);

        return [
            'pct' => $pct,
            'direction' => $pct > 0.0 ? 'up' : ($pct < 0.0 ? 'down' : 'flat'),
        ];
    }
}
