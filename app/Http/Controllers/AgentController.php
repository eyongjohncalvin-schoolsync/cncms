<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\AgentData;
use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Models\Agent;
use App\Models\Zone;
use App\Services\AgentService;
use App\Services\ZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web (session-auth, Inertia) counterpart to Api\AgentController — same
 * AgentService/Requests, rendered as Inertia pages instead of JSON. See
 * web-admin-spec.md section 3.9.
 */
class AgentController extends Controller
{
    public function __construct(
        private readonly AgentService $agents,
        private readonly ZoneService $zones,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Agent::class);

        $filters = $request->only(['zone_uuid', 'status']);

        $paginated = $this->agents->list($filters, 25);

        $paginated->through(fn (Agent $agent): array => [
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
        ]);

        return Inertia::render('Agents/Index', [
            'filters' => $filters,
            'agents' => $this->paginatorProps($paginated),
            'zones' => $this->zonesWithStats(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Agent::class);

        return Inertia::render('Agents/Create', [
            'zones' => $this->zones->all(),
        ]);
    }

    public function store(StoreAgentRequest $request): RedirectResponse
    {
        $this->agents->create(AgentData::fromArray($request->validated()));

        return redirect()->route('agents.index')->with('success', 'Agent created.');
    }

    public function edit(Agent $agent): Response
    {
        $this->authorize('update', $agent);

        $agent = $this->agents->findOrFail($agent->uuid);

        return Inertia::render('Agents/Edit', [
            'agent' => [
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
            ],
            'zones' => $this->zones->all(),
        ]);
    }

    public function update(UpdateAgentRequest $request, Agent $agent): RedirectResponse
    {
        $this->agents->update($agent, AgentData::fromArray($request->validated()));

        return redirect()->route('agents.index')->with('success', 'Agent updated.');
    }

    public function destroy(Agent $agent): RedirectResponse
    {
        $this->authorize('delete', $agent);

        $this->agents->delete($agent);

        return redirect()->route('agents.index')->with('success', 'Agent deleted.');
    }

    /**
     * Zone list for the Agents/Index "Change Zone" quick action, enriched
     * beyond ZoneService::all()'s bare uuid/name/town with the per-zone
     * customer/active-agent counts the office needs to judge a reassignment
     * before confirming it — workload (customer_count) and whether the
     * destination/source zone would end up over- or under-covered
     * (agent_count + agent_names, active agents only, since an inactive
     * agent isn't really "covering" a zone). Queried directly against the
     * Zone model here rather than via ZoneService/ZoneRepository, which are
     * being touched concurrently elsewhere this session — this keeps the
     * change isolated to this controller.
     *
     * @return array<int, array{uuid: string, name: string, town: string, customer_count: int, agent_count: int, agent_names: array<int, string>}>
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
                'town' => $zone->town,
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
