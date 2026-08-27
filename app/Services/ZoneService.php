<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ZoneData;
use App\Models\Zone;
use App\Repositories\Contracts\BranchRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ZoneService
{
    public function __construct(
        private readonly ZoneRepositoryInterface $zones,
        private readonly BranchRepositoryInterface $branches,
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->zones->paginate($filters, $perPage);
    }

    /**
     * Full zone list backing every zone dropdown/select input across
     * Customers/Agents/Payments/Manuscripts filters. Zones barely ever
     * change (29 of them), so this is cached for an hour and explicitly
     * invalidated on create/update/delete below.
     *
     * @return Collection<int, Zone>
     */
    public function all(): Collection
    {
        return Cache::remember('zones:all', now()->addHour(), fn (): Collection => $this->zones->all());
    }

    public function findOrFail(string $uuid): Zone
    {
        $zone = $this->zones->findByUuid($uuid);

        if (! $zone) {
            throw new ModelNotFoundException("Zone [{$uuid}] not found.");
        }

        return $zone;
    }

    public function create(ZoneData $data): Zone
    {
        $branchId = $this->resolveBranchId($data->branchUuid);

        $zone = $this->zones->create($branchId, $data);

        Cache::forget('zones:all');

        return $zone->load('branch');
    }

    public function update(Zone $zone, ZoneData $data): Zone
    {
        $branchId = $data->branchUuid !== null ? $this->resolveBranchId($data->branchUuid) : null;

        $zone = $this->zones->update($zone, $data, $branchId);

        Cache::forget('zones:all');

        return $zone->load('branch');
    }

    /**
     * customers.zone_id and agents.zone_id are both restrictOnDelete() —
     * deleting a zone still referenced by either raises a raw Postgres
     * RESTRICT-violation QueryException (SQLSTATE 23001) if left unchecked,
     * surfacing an ugly, unfriendly error straight to the user. Checked
     * up front with plain counts rather than letting the exception bubble
     * and catching the SQLSTATE, since a friendly "N customers/agents are
     * still assigned" message needs the actual counts anyway.
     */
    public function delete(Zone $zone): void
    {
        $customerCount = $zone->customers()->count();
        $agentCount = $zone->agents()->count();

        if ($customerCount > 0 || $agentCount > 0) {
            $parts = [];

            if ($customerCount > 0) {
                $parts[] = $customerCount === 1 ? '1 customer' : "{$customerCount} customers";
            }

            if ($agentCount > 0) {
                $parts[] = $agentCount === 1 ? '1 agent' : "{$agentCount} agents";
            }

            throw ValidationException::withMessages([
                'zone' => ['Cannot delete this zone — '.implode(' and ', $parts).' still assigned to it. Reassign or remove them first.'],
            ]);
        }

        $this->zones->delete($zone);

        Cache::forget('zones:all');
    }

    /**
     * Resolves the external-facing branch_uuid to an internal branch_id.
     * When no branch was explicitly picked (branch_uuid omitted), defaults
     * to the sole existing branch so zone creation doesn't suddenly demand
     * an extra decision when there's nothing to actually choose from yet —
     * see branches-and-locations.md section 8. Once more than one branch
     * exists, an explicit branch_uuid becomes required (zones.branch_id is
     * NOT NULL, so there is no other safe default at that point).
     */
    private function resolveBranchId(?string $branchUuid): int
    {
        if ($branchUuid !== null) {
            $branch = $this->branches->findByUuid($branchUuid);

            if (! $branch) {
                throw ValidationException::withMessages(['branch_uuid' => ['The selected branch does not exist.']]);
            }

            return $branch->id;
        }

        $branches = $this->branches->all();

        if ($branches->count() === 1) {
            return $branches->first()->id;
        }

        throw ValidationException::withMessages(['branch_uuid' => ['The branch_uuid field is required.']]);
    }
}
