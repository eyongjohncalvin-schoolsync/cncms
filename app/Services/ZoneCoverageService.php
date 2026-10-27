<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\Zone;
use App\Models\ZoneAgent;
use App\Support\BranchLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which zones each field agent covers (App\Models\ZoneAgent) — the only
 * writer. Management decides how zones are shared: one agent may cover one
 * zone, several, or all of them, and a zone may have helpers besides its one
 * responsible collector (the name on its bills).
 */
class ZoneCoverageService
{
    /**
     * The agent now covers this zone and only this zone — what assigning an
     * agent to a zone on the agent form means. They become its responsible
     * collector when it has none, else a helper.
     */
    public function coverOnly(Agent $agent, Zone $zone, ?User $actor): void
    {
        DB::transaction(function () use ($agent, $zone, $actor): void {
            ZoneAgent::query()->withoutGlobalScope(BranchScope::class)
                ->where('agent_id', $agent->id)->where('zone_id', '!=', $zone->id)->delete();

            $this->addZone($agent, $zone, $actor);
        });
    }

    /**
     * The agent also covers this zone — its responsible collector when it has
     * none yet, else a helper. Already covering it changes nothing.
     */
    public function addZone(Agent $agent, Zone $zone, ?User $actor): ZoneAgent
    {
        return DB::transaction(function () use ($agent, $zone, $actor): ZoneAgent {
            $this->assertSameBranch($agent, $zone);
            $this->lockZone($zone->id);

            $existing = ZoneAgent::query()->withoutGlobalScope(BranchScope::class)
                ->where('agent_id', $agent->id)->where('zone_id', $zone->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $hasResponsible = ZoneAgent::query()->withoutGlobalScope(BranchScope::class)
                ->where('zone_id', $zone->id)->where('role', ZoneAgent::ROLE_RESPONSIBLE)->exists();

            return ZoneAgent::query()->create([
                'zone_id' => $zone->id,
                'agent_id' => $agent->id,
                'role' => $hasResponsible ? ZoneAgent::ROLE_HELPER : ZoneAgent::ROLE_RESPONSIBLE,
                'assigned_by' => $actor?->id,
            ]);
        });
    }

    /**
     * Everything the Zone coverage screen shows for one branch, in four
     * queries whatever its size: each zone with its active customers and who
     * covers it, the branch's agents, and the customer limit. Workload is
     * worked out from these on the page itself (see workloads()), so a
     * preview of any change is instant.
     *
     * @return array{
     *     zones: list<array{uuid: string, name: string, customers: int, responsible: ?string, helpers: list<string>}>,
     *     agents: list<array{uuid: string, name: string, active: bool}>,
     *     limit: ?int, branch_limit: ?int, headquarters_limit: ?int,
     * }
     */
    public function overview(Branch $branch): array
    {
        $zones = Zone::query()->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch->id)
            ->withCount(['customers as active_customers' => fn ($query) => $query->withoutGlobalScope(BranchScope::class)->where('status', 'active')])
            ->orderBy('name')
            ->get(['id', 'uuid', 'name']);

        $agents = Agent::query()->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch->id)
            ->orderByRaw("status = 'active' DESC")->orderBy('name')
            ->get(['id', 'uuid', 'name', 'status']);
        $agentUuids = $agents->pluck('uuid', 'id');

        $coverage = ZoneAgent::query()->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch->id)
            ->get(['zone_id', 'agent_id', 'role'])
            ->groupBy('zone_id');

        $headquartersLimit = Company::cached()?->agent_customer_limit;

        return [
            'zones' => $zones->map(function (Zone $zone) use ($coverage, $agentUuids): array {
                $rows = $coverage->get($zone->id, collect());

                return [
                    'uuid' => $zone->uuid,
                    'name' => $zone->name,
                    'customers' => (int) $zone->active_customers,
                    'responsible' => $agentUuids[$rows->firstWhere('role', ZoneAgent::ROLE_RESPONSIBLE)?->agent_id] ?? null,
                    'helpers' => $rows->where('role', ZoneAgent::ROLE_HELPER)->map(fn (ZoneAgent $row): ?string => $agentUuids[$row->agent_id] ?? null)->filter()->values()->all(),
                ];
            })->values()->all(),
            'agents' => $agents->map(fn (Agent $agent): array => [
                'uuid' => $agent->uuid,
                'name' => $agent->name,
                'active' => $agent->status === 'active',
            ])->values()->all(),
            'limit' => $branch->agent_customer_limit ?? $headquartersLimit,
            'branch_limit' => $branch->is_headquarters ? null : $branch->agent_customer_limit,
            'headquarters_limit' => $headquartersLimit,
        ];
    }

    /**
     * Customers each agent looks after: every zone they cover counts its
     * active customers shared equally among the agents covering it (a zone
     * of 120 covered by two agents is 60 each). The Zone coverage page's
     * preview uses the same rule (resources/tsx/pages/Zones/Coverage.tsx).
     *
     * @param  array{zones: list<array{customers: int, responsible: ?string, helpers: list<string>}>}  $overview
     * @return array<string, float> agent uuid => customers
     */
    public static function workloads(array $overview): array
    {
        $load = [];

        foreach ($overview['zones'] as $zone) {
            $covering = array_values(array_filter([$zone['responsible'], ...$zone['helpers']]));

            foreach ($covering as $agentUuid) {
                $load[$agentUuid] = ($load[$agentUuid] ?? 0.0) + $zone['customers'] / count($covering);
            }
        }

        return $load;
    }

    /**
     * Zones in the caller's reach (BranchScope) with no ACTIVE responsible
     * collector — nobody accountable for their money, and no collector on
     * their bills. One query.
     */
    public function uncoveredZoneCount(): int
    {
        return Zone::query()
            ->whereDoesntHave('coverage', fn ($query) => $query->withoutGlobalScope(BranchScope::class)
                ->where('role', ZoneAgent::ROLE_RESPONSIBLE)
                ->whereHas('agent', fn ($agent) => $agent->withoutGlobalScope(BranchScope::class)->where('status', 'active')))
            ->count();
    }

    /**
     * Zones covered and customers looked after (same sharing rule as
     * workloads()) for any number of agents, in one query — for lists such as
     * the Agents page, which have no overview at hand.
     *
     * @param  list<int>  $agentIds
     * @return array<int, array{zones: int, customers: float}> agent id => workload; agents covering nothing are absent
     */
    public function workloadsOf(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($agentIds), '?'));

        $rows = (new ZoneAgent)->getConnection()->select(<<<SQL
            WITH mine AS (
                SELECT agent_id, zone_id FROM zone_agents WHERE agent_id IN ({$placeholders})
            ), sharing AS (
                SELECT zone_id, COUNT(*) AS agents FROM zone_agents WHERE zone_id IN (SELECT zone_id FROM mine) GROUP BY zone_id
            ), active AS (
                SELECT zone_id, COUNT(*) AS customers FROM customers
                WHERE status = 'active' AND deleted_at IS NULL AND zone_id IN (SELECT zone_id FROM mine)
                GROUP BY zone_id
            )
            SELECT mine.agent_id, COUNT(*) AS zones, COALESCE(SUM(COALESCE(active.customers, 0)::numeric / sharing.agents), 0) AS customers
            FROM mine
            JOIN sharing ON sharing.zone_id = mine.zone_id
            LEFT JOIN active ON active.zone_id = mine.zone_id
            GROUP BY mine.agent_id
            SQL, $agentIds);

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->agent_id] = ['zones' => (int) $row->zones, 'customers' => (float) $row->customers];
        }

        return $result;
    }

    /**
     * Busy from 80% of the limit, overloaded above it; null when no limit is
     * set (no judgement). Same rule as the page (@/lib/zoneCoverage).
     */
    public static function status(float $customers, ?int $limit): ?string
    {
        if ($limit === null || $limit <= 0) {
            return null;
        }

        if ($customers > $limit) {
            return 'overloaded';
        }

        return $customers >= $limit * 0.8 ? 'busy' : 'normal';
    }

    /**
     * Applies one change from the Zone coverage screen to the given zones of
     * the branch, in one transaction:
     *  - `responsible`: $agent becomes each zone's responsible collector.
     *    The previous one is removed from the zone, or kept as a helper when
     *    $keepPreviousAsHelper.
     *  - `helper`: $agent also covers each zone as a helper (no change where
     *    they already cover it).
     *  - `remove`: $agent no longer covers the zones.
     *  - `clear`: the zones have no responsible collector.
     *
     * Every zone and the agent must belong to $branch; an inactive agent is
     * never given zones. Agents left not covering their home zone get one
     * they cover as their home (what app builds before 1.0.1 show).
     *
     * @param  list<string>  $zoneUuids
     * @return int zones changed
     */
    public function apply(Branch $branch, string $action, ?Agent $agent, array $zoneUuids, bool $keepPreviousAsHelper, User $actor): int
    {
        if (! in_array($action, ['responsible', 'helper', 'remove', 'clear'], true)) {
            throw ValidationException::withMessages(['action' => 'Unknown change.']);
        }

        if ($action !== 'clear' && ($agent === null || $agent->branch_id !== $branch->id)) {
            throw ValidationException::withMessages(['agent_uuid' => 'Choose an agent of this branch.']);
        }

        if (in_array($action, ['responsible', 'helper'], true) && $agent?->status !== 'active') {
            throw ValidationException::withMessages(['agent_uuid' => "{$agent?->name} is inactive — reactivate them before giving them zones."]);
        }

        return DB::transaction(function () use ($branch, $action, $agent, $zoneUuids, $keepPreviousAsHelper, $actor): int {
            $zones = Zone::query()->withoutGlobalScope(BranchScope::class)
                ->whereIn('uuid', array_values(array_unique($zoneUuids)))
                ->orderBy('id')->lockForUpdate()->get(['id', 'uuid', 'branch_id']);
            // New zone_agents rows take their zone's branch from memory, not a query each.
            BranchLookup::remember($zones);

            if ($zones->isEmpty() || $zones->count() !== count(array_unique($zoneUuids)) || $zones->contains(fn (Zone $zone): bool => $zone->branch_id !== $branch->id)) {
                throw ValidationException::withMessages(['zone_uuids' => 'Choose zones of this branch.']);
            }

            $rows = ZoneAgent::query()->withoutGlobalScope(BranchScope::class)->whereIn('zone_id', $zones->pluck('id'))->get()->groupBy('zone_id');
            $touchedAgentIds = $agent !== null ? [$agent->id] : [];
            $changed = 0;

            foreach ($zones as $zone) {
                $zoneRows = $rows->get($zone->id, collect());
                $mine = $agent !== null ? $zoneRows->firstWhere('agent_id', $agent->id) : null;
                $responsible = $zoneRows->firstWhere('role', ZoneAgent::ROLE_RESPONSIBLE);

                switch ($action) {
                    case 'responsible':
                        if ($responsible?->agent_id === $agent->id) {
                            continue 2;
                        }

                        if ($responsible !== null) {
                            $touchedAgentIds[] = $responsible->agent_id;
                            $keepPreviousAsHelper ? $responsible->update(['role' => ZoneAgent::ROLE_HELPER]) : $responsible->delete();
                        }

                        $mine !== null
                            ? $mine->update(['role' => ZoneAgent::ROLE_RESPONSIBLE, 'assigned_by' => $actor->id])
                            : ZoneAgent::query()->create(['zone_id' => $zone->id, 'agent_id' => $agent->id, 'role' => ZoneAgent::ROLE_RESPONSIBLE, 'assigned_by' => $actor->id]);
                        break;

                    case 'helper':
                        if ($mine !== null) {
                            continue 2;
                        }

                        ZoneAgent::query()->create(['zone_id' => $zone->id, 'agent_id' => $agent->id, 'role' => ZoneAgent::ROLE_HELPER, 'assigned_by' => $actor->id]);
                        break;

                    case 'remove':
                        if ($mine === null) {
                            continue 2;
                        }

                        $mine->delete();
                        break;

                    case 'clear':
                        if ($responsible === null) {
                            continue 2;
                        }

                        $touchedAgentIds[] = $responsible->agent_id;
                        $responsible->delete();
                        break;
                }

                $changed++;
            }

            $this->rehome(array_values(array_unique($touchedAgentIds)));

            return $changed;
        });
    }

    /**
     * Keeps each agent's home zone (agents.zone_id) one they still cover, so
     * app builds before 1.0.1 never show a zone the agent no longer works.
     * An agent who covers nothing keeps their home zone (it anchors their
     * branch) — and, covering nothing, sees nothing.
     *
     * @param  list<int>  $agentIds
     */
    private function rehome(array $agentIds): void
    {
        if ($agentIds === []) {
            return;
        }

        $covered = ZoneAgent::query()->withoutGlobalScope(BranchScope::class)
            ->whereIn('agent_id', $agentIds)->orderBy('zone_id')->get(['agent_id', 'zone_id'])->groupBy('agent_id');

        Agent::query()->withoutGlobalScope(BranchScope::class)->whereIn('id', $agentIds)->get()
            ->each(function (Agent $agent) use ($covered): void {
                $zoneIds = $covered->get($agent->id, collect())->pluck('zone_id')->map(fn ($id): int => (int) $id);

                if ($zoneIds->isNotEmpty() && ! $zoneIds->contains($agent->zone_id)) {
                    $agent->update(['zone_id' => $zoneIds->first()]);
                }
            });
    }

    /**
     * Each zone's responsible collector's name, for printing on bills — one
     * query for any number of zones. Zones without one are absent.
     *
     * @param  list<int>  $zoneIds
     * @return array<int, string> zone id => collector name
     */
    public function responsibleNames(array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }

        return ZoneAgent::query()->withoutGlobalScope(BranchScope::class)
            ->join('agents', 'agents.id', '=', 'zone_agents.agent_id')
            ->whereIn('zone_agents.zone_id', $zoneIds)
            ->where('zone_agents.role', ZoneAgent::ROLE_RESPONSIBLE)
            ->pluck('agents.name', 'zone_agents.zone_id')
            ->mapWithKeys(fn ($name, $zoneId): array => [(int) $zoneId => (string) $name])
            ->all();
    }

    private function assertSameBranch(Agent $agent, Zone $zone): void
    {
        if ($agent->branch_id !== $zone->branch_id) {
            throw ValidationException::withMessages(['zone_uuid' => ["{$zone->name} is in another branch — an agent covers zones of their own branch only."]]);
        }
    }

    /** Serialises changes to one zone's coverage (its single responsible collector). */
    private function lockZone(int $zoneId): void
    {
        Zone::query()->withoutGlobalScope(BranchScope::class)->whereKey($zoneId)->lockForUpdate()->first();
    }
}
