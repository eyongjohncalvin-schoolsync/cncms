<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\AgentData;
use App\Models\Agent;
use App\Models\StaffSalary;
use App\Models\User;
use App\Repositories\Contracts\AgentRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
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
        private readonly SalaryService $salaries,
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
        $cacheKey = 'agents:list:'.(TenantContext::currentBranchId() ?? 'all').':'.md5(json_encode([$filters, $perPage, $page]));

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
        $agent = $this->agents->findByUserId($userId, ['zone']);

        if (! $agent) {
            throw new ModelNotFoundException("No agent record found for user [{$userId}].");
        }

        return $agent;
    }

    public function create(AgentData $data): Agent
    {
        $zoneId = $this->resolveZoneId($data->zoneUuid);
        $userId = $this->resolveUserId($data->userUuid);

        $agent = $this->agents->create($zoneId, $userId, $data);

        Cache::forget('agents:all');
        $this->recordAssignment($agent);
        $this->recordSalary($agent, null);

        return $agent->load(['zone', 'user']);
    }

    public function update(Agent $agent, AgentData $data): Agent
    {
        $zoneId = $data->zoneUuid !== null ? $this->resolveZoneId($data->zoneUuid) : null;
        $userId = $data->userUuid !== null ? $this->resolveUserId($data->userUuid) : null;
        $previousSalary = (string) $agent->salary;

        $agent = $this->agents->update($agent, $data, $zoneId, $userId);

        Cache::forget('agents:all');
        $this->recordAssignment($agent);

        if (bccomp((string) $agent->salary, $previousSalary, 2) !== 0) {
            $this->recordSalary($agent, $previousSalary);
        }

        return $agent->load(['zone', 'user']);
    }

    /**
     * Branches v2 D8: an agent's salary follows the salary rules — on their
     * current grade, inside its range it is approved at once; outside, it
     * waits for headquarters and the agent keeps $previousSalary until then.
     * Agents without a login have no salary history and keep the old direct field.
     */
    private function recordSalary(Agent $agent, ?string $previousSalary): void
    {
        $actor = auth()->user();

        if ($agent->user_id === null || ! $actor instanceof User) {
            return;
        }

        $salary = $this->salaries->set((int) $agent->user_id, $agent->branch_id, (string) $agent->salary, $this->salaries->currentGrade((int) $agent->user_id), 'Set on the agent form', $actor);

        if ($salary->status === StaffSalary::STATUS_PENDING && $previousSalary !== null) {
            $agent->forceFill(['salary' => $previousSalary])->save();
        }
    }

    public function delete(Agent $agent): void
    {
        $this->agents->delete($agent);

        Cache::forget('agents:all');
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
