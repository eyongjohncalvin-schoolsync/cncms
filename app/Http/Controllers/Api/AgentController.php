<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\AgentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Services\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function __construct(
        private readonly AgentService $agents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Agent::class);

        $perPage = (int) $request->integer('per_page', 25);

        $filters = $request->only(['zone_uuid', 'status']);

        $agents = $this->agents->list($filters, $perPage);

        return AgentResource::collection($agents)->response();
    }

    public function show(Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        $agent = $this->agents->findOrFail($agent->uuid);

        return (new AgentResource($agent))->response();
    }

    public function store(StoreAgentRequest $request): JsonResponse
    {
        $agent = $this->agents->create(AgentData::fromArray($request->validated()));

        return (new AgentResource($agent))->response()->setStatusCode(201);
    }

    public function update(UpdateAgentRequest $request, Agent $agent): JsonResponse
    {
        $agent = $this->agents->update($agent, AgentData::fromArray($request->validated()));

        return (new AgentResource($agent))->response();
    }

    public function destroy(Agent $agent): JsonResponse
    {
        $this->authorize('delete', $agent);

        $this->agents->delete($agent);

        return response()->json(['message' => 'Agent deleted']);
    }
}
