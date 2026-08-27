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

    /**
     * The Agent row linked to a given central `users.id`, used by
     * GET /api/v1/agents/me to resolve "my own profile" — see
     * App\Http\Controllers\Api\AgentController::me(). Not branch-scoped:
     * a caller looking up their own record isn't subject to the branch
     * fence that guards browsing *other* agents.
     */
    public function findByUserId(int $userId, array $with = []): ?Agent;

    public function create(int $zoneId, ?int $userId, AgentData $data): Agent;

    public function update(Agent $agent, AgentData $data, ?int $zoneId = null, ?int $userId = null): Agent;

    public function delete(Agent $agent): bool;
}
