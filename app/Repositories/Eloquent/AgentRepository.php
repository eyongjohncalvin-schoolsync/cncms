<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\AgentData;
use App\Models\Agent;
use App\Repositories\Concerns\ScopesByBranch;
use App\Repositories\Contracts\AgentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class AgentRepository implements AgentRepositoryInterface
{
    use ScopesByBranch;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scopeToBranch(Agent::query(), $this->currentBranchId())
            ->with(['zone', 'user'])
            ->when($filters['zone_id'] ?? null, fn ($query, $zoneId) => $query->where('zone_id', $zoneId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid, array $with = []): ?Agent
    {
        return $this->scopeToBranch(Agent::query(), $this->currentBranchId())
            ->with($with)
            ->where('uuid', $uuid)
            ->first();
    }

    public function create(int $zoneId, ?int $userId, AgentData $data): Agent
    {
        return Agent::query()->create([
            'zone_id' => $zoneId,
            'user_id' => $userId,
            ...$data->toAttributes(),
        ]);
    }

    public function update(Agent $agent, AgentData $data, ?int $zoneId = null, ?int $userId = null): Agent
    {
        $attributes = $data->toAttributes();

        if ($zoneId !== null) {
            $attributes['zone_id'] = $zoneId;
        }

        if ($userId !== null) {
            $attributes['user_id'] = $userId;
        }

        $agent->update($attributes);

        return $agent;
    }

    public function delete(Agent $agent): bool
    {
        return (bool) $agent->delete();
    }
}
