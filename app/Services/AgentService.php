<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\AgentData;
use App\Models\Agent;
use App\Models\User;
use App\Repositories\Contracts\AgentRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class AgentService
{
    public function __construct(
        private readonly AgentRepositoryInterface $agents,
        private readonly ZoneRepositoryInterface $zones,
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (! empty($filters['zone_uuid'])) {
            $filters['zone_id'] = $this->resolveZoneId($filters['zone_uuid']);
        }

        return $this->agents->paginate($filters, $perPage);
    }

    public function findOrFail(string $uuid): Agent
    {
        $agent = $this->agents->findByUuid($uuid, ['zone', 'user']);

        if (! $agent) {
            throw new ModelNotFoundException("Agent [{$uuid}] not found.");
        }

        return $agent;
    }

    public function create(AgentData $data): Agent
    {
        $zoneId = $this->resolveZoneId($data->zoneUuid);
        $userId = $this->resolveUserId($data->userUuid);

        $agent = $this->agents->create($zoneId, $userId, $data);

        return $agent->load(['zone', 'user']);
    }

    public function update(Agent $agent, AgentData $data): Agent
    {
        $zoneId = $data->zoneUuid !== null ? $this->resolveZoneId($data->zoneUuid) : null;
        $userId = $data->userUuid !== null ? $this->resolveUserId($data->userUuid) : null;

        $agent = $this->agents->update($agent, $data, $zoneId, $userId);

        return $agent->load(['zone', 'user']);
    }

    public function delete(Agent $agent): void
    {
        $this->agents->delete($agent);
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

    private function resolveUserId(?string $userUuid): ?int
    {
        if (! $userUuid) {
            return null;
        }

        $user = User::query()->where('uuid', $userUuid)->first();

        if (! $user) {
            throw ValidationException::withMessages(['user_uuid' => ['The selected user does not exist.']]);
        }

        return $user->id;
    }
}
