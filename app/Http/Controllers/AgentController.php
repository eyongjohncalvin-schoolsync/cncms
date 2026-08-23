<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\AgentData;
use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Models\Agent;
use App\Models\Zone;
use App\Services\AgentService;
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
            'zones' => Zone::query()->orderBy('name')->get(['uuid', 'name', 'town']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Agent::class);

        return Inertia::render('Agents/Create', [
            'zones' => Zone::query()->orderBy('name')->get(['uuid', 'name', 'town']),
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
            'zones' => Zone::query()->orderBy('name')->get(['uuid', 'name', 'town']),
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
