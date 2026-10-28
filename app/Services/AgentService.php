<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Repositories\Contracts\AgentRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Support\CacheVersion;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgentService
{
    /**
     * Deliberately does NOT constructor-inject App\Support\TenantContext —
     * see App\Services\CustomerService's identical constructor doc comment
     * for why (a Service in this same layer is resolved outside any tenant
     * HTTP request by at least one test). list() below uses
     * TenantContext::currentBranchId() instead.
     */
    public function __construct(
        private readonly AgentRepositoryInterface $agents,
        private readonly ZoneRepositoryInterface $zones,
        private readonly StaffAssignmentService $assignments,
        private readonly ZoneCoverageService $coverage,
    ) {}

    /**
     * Branches v2 (D7): an agent works in their zone's branch — a zone in
     * another branch is a transfer, kept in the staff assignment history.
     */
    private function recordAssignment(Agent $agent): void
    {
        if ($agent->user_id !== null) {
            $this->assignments->record((int) $agent->user_id, $agent->branch_id, 'agent', auth()->user());
        }
    }

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (! empty($filters['zone_uuid'])) {
            $filters['zone_id'] = $this->resolveZoneId($filters['zone_uuid']);
        }

        $page = Paginator::resolveCurrentPage() ?: 1;

        // Branch fence baked into the key — see the identical note on
        // App\Services\CustomerService::list().
        // CacheVersion: agent and coverage changes retire it ('agents:all' was never the written key).
        $cacheKey = 'agents:list:'.CacheVersion::of(CacheVersion::AGENTS).':'.(TenantContext::currentBranchId() ?? 'all').':'.md5(json_encode([$filters, $perPage, $page]));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(60),
            fn (): LengthAwarePaginator => $this->agents->paginate($filters, $perPage)
        );
    }

    public function findOrFail(string $uuid): Agent
    {
        $agent = $this->agents->findByUuid($uuid, ['zone', 'user']);

        if (! $agent) {
            throw new ModelNotFoundException("Agent [{$uuid}] not found.");
        }

        return $agent;
    }

    /**
     * The Agent row belonging to the currently-authenticated user, for
     * "my own profile" (GET /api/v1/agents/me). Deliberately not
     * branch-cached like list() — this is a single self-scoped row, not a
     * roster listing.
     */
    public function findForUser(int $userId): Agent
    {
        $agent = $this->agents->findByUserId($userId, [
            'zone',
            // Every zone they cover, for the app's profile and zones screens.
            'coverage' => fn ($query) => $query->withoutGlobalScope(BranchScope::class)->with(['zone' => fn ($zone) => $zone->withoutGlobalScope(BranchScope::class)]),
        ]);

        if (! $agent) {
            throw new ModelNotFoundException("No agent record found for user [{$userId}].");
        }

        return $agent;
    }

    /**
     * The Agents page's "Change zone": the agent now covers that zone only
     * (it becomes their home zone). Their old coverage goes first — their
     * branch may change, and the database keeps coverage in it. Everything
     * else about the person (name, role, salary, active or not) is their
     * user account's, on the Users page (owner, 2026-10-28).
     */
    public function changeZone(Agent $agent, string $zoneUuid): Agent
    {
        $zoneId = $this->resolveZoneId($zoneUuid);

        if ($zoneId === $agent->zone_id && $agent->coverage()->withoutGlobalScope(BranchScope::class)->count() === 1) {
            return $agent;
        }

        $agent = DB::transaction(function () use ($agent, $zoneId): Agent {
            $agent->coverage()->withoutGlobalScope(BranchScope::class)->delete();
            $agent->update(['zone_id' => $zoneId]);
            $this->coverage->coverOnly($agent, $agent->zone()->withoutGlobalScope(BranchScope::class)->firstOrFail(), auth()->user());

            return $agent;
        });

        $this->recordAssignment($agent);

        return $agent->load(['zone', 'user']);
    }

    private function resolveZoneId(?string $zoneUuid): int
    {
        if (! $zoneUuid) {
            throw ValidationException::withMessages(['zone_uuid' => ['The zone_uuid field is required.']]);
        }

        $zone = $this->zones->findByUuid($zoneUuid);

        if (! $zone) {
            throw ValidationException::withMessages(['zone_uuid' => ['The selected zone does not exist.']]);
        }

        return $zone->id;
    }
}
