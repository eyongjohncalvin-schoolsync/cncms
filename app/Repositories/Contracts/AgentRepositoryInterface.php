<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\AgentData;
use App\Models\Agent;
use Illuminate\Pagination\LengthAwarePaginator;

interface AgentRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'zone_id', 'status'.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findByUuid(string $uuid, array $with = []): ?Agent;

    public function create(int $zoneId, ?int $userId, AgentData $data): Agent;

    public function update(Agent $agent, AgentData $data, ?int $zoneId = null, ?int $userId = null): Agent;

    public function delete(Agent $agent): bool;
}
