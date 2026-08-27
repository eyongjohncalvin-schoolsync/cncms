<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BulkDisconnectCustomersRequest;
use App\Http\Requests\BulkReconnectCustomersRequest;
use App\Http\Requests\BulkSuspendCustomersRequest;
use App\Models\Agent;
use App\Models\Customer;
use App\Models\Zone;
use App\Services\CustomerEligibilityService;
use App\Services\CustomerService;
use App\Services\CustomerStatusService;
use App\Services\ZoneService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dedicated bulk customer-status workboard — "select 5-6 customers,
 * disconnect/suspend/reconnect them together" is the primary interaction
 * here, distinct from the single-customer quick actions that also still
 * live on Customers/Show.tsx and Customers/Index.tsx (both call the very
 * same App\Services\CustomerStatusService, just its singular methods
 * instead of the *Many() ones this controller drives).
 *
 * Deliberately its own top-level page/nav item (/disconnections) rather
 * than a tab bolted onto Customers/Index.tsx, both because bulk status
 * management is a distinct workflow from the general customer directory,
 * and to leave room for a second tab/filter here: `?eligible=1` switches
 * the SAME page to the arrears-based "flagged for non-payment" view
 * (App\Services\CustomerEligibilityService) instead of the plain
 * status-board list — see eligibilityIndex() below. That view is gated by
 * a separate, broader policy ability (viewEligibilityBoard, which also
 * admits `agent`) than the rest of this controller (viewStatusBoard,
 * bulkDisconnect/bulkSuspend/bulkReconnect, all super/admin/manager-only),
 * so an `agent` can see their own zone's flagged customers but still can't
 * reach the general status board or actually execute a status change.
 */
class DisconnectionsController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerStatusService $statuses,
        private readonly ZoneService $zones,
        private readonly CustomerEligibilityService $eligibility,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        return $request->boolean('eligible')
            ? $this->eligibilityIndex($request)
            : $this->statusBoardIndex($request);
    }

    private function statusBoardIndex(Request $request): Response
    {
        $this->authorize('viewStatusBoard', Customer::class);

        $filters = $request->only(['zone_uuid', 'status', 'search']);

        $paginator = $this->customers->list($filters, self::PER_PAGE);

        return Inertia::render('Disconnections/Index', [
            'customers' => [
                'data' => collect($paginator->items())
                    ->map(fn (Customer $customer) => $this->shapeCustomer($customer))
                    ->all(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            'zones' => $this->zonesForSelect(),
            'filters' => [
                'zone_uuid' => $filters['zone_uuid'] ?? null,
                'status' => $filters['status'] ?? null,
                'search' => $filters['search'] ?? null,
                'eligible' => false,
            ],
            'isAgentScoped' => false,
        ]);
    }

    /**
     * The arrears-based "flagged for non-payment" tab. An `agent` is
     * force-scoped to their own zone (resolved via their Agent row) — any
     * `zone_uuid` query value they pass is ignored rather than trusted, so
     * they can't view another zone by tampering with the query string.
     * Office roles (super/admin/manager) see every zone by default with an
     * optional zone_uuid filter, exactly like the status board above.
     */
    private function eligibilityIndex(Request $request): Response
    {
        $this->authorize('viewEligibilityBoard', Customer::class);

        $isAgent = $this->context->is('agent');
        $zoneUuid = $request->string('zone_uuid')->toString() ?: null;
        $zoneId = null;

        if ($isAgent) {
            $agent = Agent::query()->with('zone')->where('user_id', $request->user()->id)->first();
            $zoneId = $agent?->zone_id;
            $zoneUuid = $agent?->zone?->uuid;
        } elseif ($zoneUuid !== null) {
            $zoneId = $this->zones->findOrFail($zoneUuid)->id;
        }

        $eligible = $this->eligibility->eligibleForDisconnection($zoneId);
        $paginator = $this->paginate($eligible, $request);

        return Inertia::render('Disconnections/Index', [
            'customers' => [
                'data' => $paginator->items(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            'zones' => $this->zonesForSelect(),
            'filters' => [
                'zone_uuid' => $zoneUuid,
                'status' => null,
                'search' => null,
                'eligible' => true,
            ],
            'isAgentScoped' => $isAgent,
        ]);
    }

    /**
     * Eligibility is computed in full (typically a small subset of active
     * customers) rather than queried page-by-page, so pagination here is
     * just an in-memory slice of the already-computed collection — kept
     * for UI/UX consistency with the status board's Pagination component,
     * not because the eligible list is expected to be large.
     *
     * @param  Collection<int, array<string, mixed>>  $eligible
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(Collection $eligible, Request $request): LengthAwarePaginator
    {
        $page = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(
            $eligible->forPage($page, self::PER_PAGE)->values(),
            $eligible->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    public function bulkDisconnect(BulkDisconnectCustomersRequest $request): RedirectResponse
    {
        $result = $this->statuses->disconnectMany($request->validated('customer_uuids'), $request->validated('note'));

        return $this->bulkRedirect($result, 'disconnected');
    }

    public function bulkSuspend(BulkSuspendCustomersRequest $request): RedirectResponse
    {
        $result = $this->statuses->suspendMany(
            $request->validated('customer_uuids'),
            $request->validated('reason'),
            $request->validated('note'),
        );

        return $this->bulkRedirect($result, 'suspended');
    }

    public function bulkReconnect(BulkReconnectCustomersRequest $request): RedirectResponse
    {
        $result = $this->statuses->reconnectMany(
            $request->validated('customer_uuids'),
            $request->validated('note'),
            $request->boolean('include_fine'),
        );

        return $this->bulkRedirect($result, 'reconnected');
    }

    /**
     * @param  array{succeeded: string[], skipped: array<string, string>}  $result
     */
    private function bulkRedirect(array $result, string $verb): RedirectResponse
    {
        $succeededCount = count($result['succeeded']);
        $skippedCount = count($result['skipped']);

        $message = $succeededCount === 1 ? "1 customer {$verb}." : "{$succeededCount} customers {$verb}.";

        if ($skippedCount > 0) {
            $message .= ' '.($skippedCount === 1 ? '1 was skipped: ' : "{$skippedCount} were skipped: ")
                .implode(' ', $result['skipped']);
        }

        return back()->with($succeededCount > 0 ? 'success' : 'error', $message);
    }

    /**
     * @return array<int, array{uuid: string, name: string, town: string}>
     */
    private function zonesForSelect(): array
    {
        return $this->zones->all()
            ->map(fn (Zone $zone) => [
                'uuid' => $zone->uuid,
                'name' => $zone->name,
                'town' => $zone->town,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeCustomer(Customer $customer): array
    {
        return [
            'uuid' => $customer->uuid,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'zone_uuid' => $customer->zone?->uuid,
            'zone_name' => $customer->zone?->name,
            'bill' => $customer->bill,
            'others' => $customer->others,
            'level' => $customer->level,
            'status' => $customer->status,
            'status_reason' => $customer->status_reason,
            'status_note' => $customer->status_note,
            'location' => $customer->location,
        ];
    }
}
