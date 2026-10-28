<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentMeResource;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Services\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Field agents over the API: read-only. A field agent is a user (owner,
 * 2026-10-28) — accounts are created on the Users page and zones given on
 * the Agents page, never here.
 */
class AgentController extends Controller
{
    public function __construct(
        private readonly AgentService $agents,
    ) {}

    /**
     * "My Profile" — the mobile app's read-only own-profile screen.
     * Deliberately scoped to `auth()->id()`, never a uuid route param: an
     * agent's own device can only ever resolve their own Agent row this
     * way, regardless of what AgentPolicy::view() technically permits for
     * office/web contexts. No policy check needed beyond auth:sanctum +
     * ResolveTenant (already applied by the route group) — the query
     * itself is what scopes this to "me", not an authorization decision.
     */
    public function me(Request $request): JsonResponse
    {
        $agent = $this->agents->findForUser($request->user()->id);

        return (new AgentMeResource($agent))->response();
    }

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
}
