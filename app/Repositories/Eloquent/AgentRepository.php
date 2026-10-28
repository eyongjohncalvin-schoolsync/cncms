<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Agent;
use App\Repositories\Concerns\ScopesByBranch;
use App\Repositories\Contracts\AgentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class AgentRepository implements AgentRepositoryInterface
{
    use ScopesByBranch;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scopeToBranchDirect(Agent::query(), $this->currentBranchId())
            ->with(['zone', 'user'])
            ->when($filters['zone_id'] ?? null, fn ($query, $zoneId) => $query->where('zone_id', $zoneId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid, array $with = []): ?Agent
    {
        return $this->scopeToBranchDirect(Agent::query(), $this->currentBranchId())
            ->with($with)
            ->where('uuid', $uuid)
            ->first();
    }

    public function findByUserId(int $userId, array $with = []): ?Agent
    {
        return Agent::query()
            ->with($with)
            ->where('user_id', $userId)
            ->first();
    }
}
