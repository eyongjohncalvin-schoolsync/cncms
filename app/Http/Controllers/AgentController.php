<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreateAgentLoginRequest;
use App\Models\Agent;
use App\Models\Company;
use App\Models\TenantUser;
use App\Models\Zone;
use App\Services\AgentService;
use App\Services\StaffAccountService;
use App\Services\ZoneCoverageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Field agents page (owner, 2026-10-28): a field agent is a user —
 * added, renamed, deactivated and paid on the Users page, their agent record
 * kept in step with their account (StaffAccountService::syncAgent()). This
 * page lists them with their zones and workload, moves an agent to another
 * zone ("Change zone"; Zones → Coverage gives several), and gives an old
 * agent record without a login one.
 */
class AgentController extends Controller
{
    public function __construct(
        private readonly AgentService $agents,
    ) {}

    public function index(Request $request, ZoneCoverageService $coverage): Response
    {
        $this->authorize('viewAny', Agent::class);

        $filters = $request->only(['zone_uuid', 'status']);

        $paginated = $this->agents->list($filters, 25);
        // Zones covered and workload for the whole page in one query.
        $workloads = $coverage->workloadsOf(collect($paginated->items())->map(fn (Agent $agent): int => $agent->id)->all());

        $paginated->through(fn (Agent $agent): array => [
            'zones_covered' => $workloads[$agent->id]['zones'] ?? 0,
            'customers_covered' => (int) round($workloads[$agent->id]['customers'] ?? 0),
            'customer_limit' => Company::forBranch($agent->branch_id)?->agent_customer_limit,
            'workload_status' => ZoneCoverageService::status($workloads[$agent->id]['customers'] ?? 0.0, Company::forBranch($agent->branch_id)?->agent_customer_limit),
            'uuid' => $agent->uuid,
            'name' => $agent->name,
            'location' => $agent->location,
            'phone' => $agent->phone,
            'zone_uuid' => $agent->zone?->uuid,
            'zone_name' => $agent->zone?->name,
            'salary' => $agent->salary,
            'email' => $agent->email,
            'dob' => $agent->dob,
            'marital_status' => $agent->marital_status,
            'children' => $agent->children,
            'status' => $agent->status,
            'last_sync_at' => $agent->last_sync_at,
            // An old record from before agents were users: offered "Create login".
            'has_login' => $agent->user_id !== null,
        ]);

        return Inertia::render('Agents/Index', [
            'filters' => $filters,
            'agents' => $this->paginatorProps($paginated),
            'zones' => $this->zonesWithStats(),
            'can_create_login' => $request->user()->can('create', TenantUser::class),
        ]);
    }

    public function changeZone(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate(['zone_uuid' => ['required', 'string']]);

        if ($agent->status !== 'active') {
            throw ValidationException::withMessages(['zone_uuid' => "{$agent->name}'s account is deactivated — reactivate them on the Users page first."]);
        }

        $this->agents->changeZone($agent, $validated['zone_uuid']);

        return redirect()->route('agents.index')->with('success', 'Zone changed.');
    }

    /**
     * An old agent record without a login becomes a field-agent user: the
     * same account as one made on the Users page, keeping the record, its
     * zones and its branch.
     */
    public function createLogin(CreateAgentLoginRequest $request, Agent $agent, StaffAccountService $accounts): RedirectResponse
    {
        if ($agent->user_id !== null) {
            throw ValidationException::withMessages(['username' => "{$agent->name} already has a login."]);
        }

        $accounts->create([...$request->validated(), 'name' => $agent->name, 'role' => 'agent'], $agent->branch_id, $request->user(), $agent);

        return redirect()->route('agents.index')->with('success', "{$agent->name} can now sign in as {$request->validated('username')}.");
    }

    /**
     * Zone list for the Agents/Index "Change Zone" quick action, enriched
     * beyond ZoneService::all()'s bare uuid/name/branch with the per-zone
     * customer/active-agent counts the office needs to judge a reassignment
     * before confirming it — workload (customer_count) and whether the
     * destination/source zone would end up over- or under-covered
     * (agent_count + agent_names, active agents only, since an inactive
     * agent isn't really "covering" a zone). Queried directly against the
     * Zone model here rather than via ZoneService/ZoneRepository, which are
     * being touched concurrently elsewhere this session — this keeps the
     * change isolated to this controller.
     *
     * @return array<int, array{uuid: string, name: string, branch_name: ?string, customer_count: int, agent_count: int, agent_names: array<int, string>}>
     */
    private function zonesWithStats(): array
    {
        return Zone::query()
            ->withCount([
                'customers',
                'agents as agent_count' => fn ($query) => $query->where('status', 'active'),
            ])
            ->with(['agents' => fn ($query) => $query->where('status', 'active')->orderBy('name')->select('id', 'zone_id', 'name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Zone $zone): array => [
                'uuid' => $zone->uuid,
                'name' => $zone->name,
                'branch_name' => $zone->branchName(),
                'customer_count' => $zone->customers_count,
                'agent_count' => $zone->agent_count,
                'agent_names' => $zone->agents->pluck('name')->all(),
            ])
            ->all();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, links: array<int, array{url: ?string, label: string, active: bool}>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    private function paginatorProps(LengthAwarePaginator $paginator): array
    {
        $array = $paginator->toArray();

        return [
            'data' => $array['data'],
            'links' => $array['links'],
            'meta' => [
                'current_page' => $array['current_page'],
                'per_page' => $array['per_page'],
                'total' => $array['total'],
                'last_page' => $array['last_page'],
            ],
        ];
    }
}
