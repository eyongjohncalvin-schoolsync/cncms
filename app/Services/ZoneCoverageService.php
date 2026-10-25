<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\Zone;
use App\Models\ZoneAgent;
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
