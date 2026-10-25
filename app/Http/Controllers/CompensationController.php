<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompensationBatchRequest;
use App\Models\ArrearsAdjustment;
use App\Models\Branch;
use App\Models\CompensationBatch;
use App\Models\FreeMonthGrant;
use App\Models\Service;
use App\Models\Zone;
use App\Services\CompensationService;
use App\Services\FreeMonthGrantService;
use App\Services\ManuscriptRunLockService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Compensation page (owner, 2026-10-25): what waits for approval (zone /
 * branch-wide and single customers), what was given lately, and what
 * compensation costs this month (free months running now, at each
 * customer's price, plus write-offs approved this month). Asking for a
 * zone- or branch-wide compensation happens here too; one customer's free
 * months are still asked for on the customer page.
 */
class CompensationController extends Controller
{
    public function __construct(
        private readonly CompensationService $compensation,
        private readonly TenantContext $context,
        private readonly ManuscriptRunLockService $lock,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CompensationBatch::class);

        $user = $request->user();
        $branchId = $this->context->branchId;
        $period = $this->lock->currentPeriod();

        $batches = CompensationBatch::query()
            ->with(['zone', 'branch', 'requestedBy', 'decidedBy'])
            ->where(fn ($query) => $query->where('status', CompensationBatch::STATUS_PENDING_APPROVAL)->orWhere('created_at', '>=', now()->subDays(60)))
            ->latest('id')
            ->limit(50)
            ->get();

        $singles = FreeMonthGrant::query()
            ->with(['customer', 'service', 'requestedBy', 'decidedBy'])
            ->where('reason', FreeMonthGrant::REASON_COMPENSATION)
            ->whereNull('compensation_batch_id')
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where(fn ($query) => $query->where('status', FreeMonthGrant::STATUS_PENDING_APPROVAL)
                ->orWhere(fn ($given) => $given->where('status', FreeMonthGrant::STATUS_APPROVED)->where('decided_at', '>=', now()->subDays(60))))
            ->latest('id')
            ->limit(300)
            ->get()
            ->groupBy('group_uuid')
            ->map(fn (Collection $group): array => $this->formatSingle($group, $user->can('approve', $group->first())))
            ->values();

        $formattedBatches = $batches->map(fn (CompensationBatch $batch): array => $this->formatBatch($batch, $user->can('approve', $batch)));

        return Inertia::render('Compensation/Index', [
            'waiting' => [
                'batches' => $formattedBatches->where('status', CompensationBatch::STATUS_PENDING_APPROVAL)->values(),
                'customers' => $singles->where('status', FreeMonthGrant::STATUS_PENDING_APPROVAL)->values(),
            ],
            'given' => [
                'batches' => $formattedBatches->where('status', CompensationBatch::STATUS_APPROVED)->values(),
                'customers' => $singles->where('status', FreeMonthGrant::STATUS_APPROVED)->values(),
            ],
            'cost' => $this->costThisMonth($period, $branchId),
            'period' => $period,
            'can_request' => $user->can('create', CompensationBatch::class),
            'max_months' => FreeMonthGrantService::maxMonths(),
            'months_without_approval' => FreeMonthGrantService::monthsWithoutApproval(),
            'branches' => Branch::query()
                ->when($this->context->isFenced(), fn ($query) => $query->whereKey($this->context->branchId))
                ->orderBy('name')
                ->get(['id', 'uuid', 'name'])
                ->map(fn (Branch $branch): array => [
                    'uuid' => $branch->uuid,
                    'name' => $branch->name,
                    'zones' => Zone::query()->where('branch_id', $branch->id)->orderBy('name')->get(['uuid', 'name'])->map->only(['uuid', 'name'])->values(),
                ])->values(),
            'services' => Service::query()->active()->orderBy('sort_order')->get(['uuid', 'name'])->map->only(['uuid', 'name'])->values(),
        ]);
    }

    public function store(StoreCompensationBatchRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $branch = Branch::query()->where('uuid', $validated['branch_uuid'])->firstOrFail();
        $zone = isset($validated['zone_uuid']) ? Zone::query()->where('uuid', $validated['zone_uuid'])->firstOrFail() : null;
        $serviceIds = ! empty($validated['service_uuids'])
            ? Service::query()->whereIn('uuid', $validated['service_uuids'])->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : null;

        $batch = $this->compensation->request($branch, $zone, $serviceIds, (int) $validated['months'], $validated['note'], $request->user());

        return back()->with('success', "Compensation asked for {$batch->customers_count} customers. Someone else must approve it.");
    }

    public function approve(Request $request, CompensationBatch $compensationBatch): RedirectResponse
    {
        $this->authorize('approve', $compensationBatch);

        $this->compensation->approve($compensationBatch, $request->user());

        return back()->with('success', "Approved — {$compensationBatch->customers_count} customers get their free months.");
    }

    public function reject(Request $request, CompensationBatch $compensationBatch): RedirectResponse
    {
        $this->authorize('reject', $compensationBatch);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->compensation->reject($compensationBatch, $request->user(), $reason);

        return back()->with('success', 'Compensation rejected.');
    }

    /**
     * What compensation costs this month: each compensation free month
     * running now, at that customer's price for the service(s) it covers,
     * plus the poor-service write-offs approved this month.
     *
     * @return array{free_months: string, customers: int, write_offs: string, total: string}
     */
    private function costThisMonth(string $period, ?int $branchId): array
    {
        $running = FreeMonthGrant::query()
            ->active()
            ->where('reason', FreeMonthGrant::REASON_COMPENSATION)
            ->where('start_period', '<=', $period)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->get()
            ->filter(fn (FreeMonthGrant $grant): bool => $grant->coversPeriod($period));

        $prices = DB::table('customer_service')
            ->whereIn('customer_id', $running->pluck('customer_id')->unique())
            ->get(['customer_id', 'service_id', 'price'])
            ->groupBy('customer_id');

        $freeMonths = $running->reduce(function (string $carry, FreeMonthGrant $grant) use ($prices): string {
            $rows = collect($prices[$grant->customer_id] ?? [])
                ->when($grant->service_id !== null, fn (Collection $rows) => $rows->where('service_id', $grant->service_id));

            return bcadd($carry, (string) $rows->sum('price'), 2);
        }, '0.00');

        $writeOffs = (string) ArrearsAdjustment::query()
            ->where('reason_category', 'goodwill_service_outage')
            ->where('status', 'approved')
            ->where('approved_at', '>=', now()->startOfMonth())
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->sum('amount');

        return [
            'free_months' => $freeMonths,
            'customers' => $running->pluck('customer_id')->unique()->count(),
            'write_offs' => bcadd($writeOffs, '0', 2),
            'total' => bcadd($freeMonths, $writeOffs, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBatch(CompensationBatch $batch, bool $canDecide): array
    {
        return [
            'uuid' => $batch->uuid,
            'where' => $batch->zone?->name ?? 'All of '.($batch->branch?->name ?? 'the branch'),
            'months' => $batch->months,
            'customers_count' => $batch->customers_count,
            'note' => $batch->note,
            'status' => $batch->status,
            'requested_by' => $batch->requestedBy?->name,
            'decided_by' => $batch->decidedBy?->name,
            'created_at' => $batch->created_at?->toIso8601String(),
            'decided_at' => $batch->decided_at?->toIso8601String(),
            'can_decide' => $canDecide,
        ];
    }

    /**
     * One customer's request (its rows share group_uuid).
     *
     * @param  Collection<int, FreeMonthGrant>  $group
     * @return array<string, mixed>
     */
    private function formatSingle(Collection $group, bool $canDecide): array
    {
        $first = $group->first();

        return [
            'uuid' => $first->uuid,
            'customer_uuid' => $first->customer?->uuid,
            'customer_name' => $first->customer?->name,
            'services' => $group->map(fn (FreeMonthGrant $grant): string => $grant->service?->name ?? 'All services')->unique()->values(),
            'months' => $first->months,
            'note' => $first->note,
            'status' => $first->status,
            'start_period' => $group->min('start_period'),
            'requested_by' => $first->requestedBy?->name,
            'decided_by' => $first->decidedBy?->name,
            'without_approval' => $first->status === FreeMonthGrant::STATUS_APPROVED && $first->decided_by === $first->requested_by,
            'created_at' => $first->created_at?->toIso8601String(),
            'can_decide' => $canDecide,
        ];
    }
}
