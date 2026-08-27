<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\ZoneData;
use App\Models\Zone;
use App\Repositories\Concerns\ScopesByBranch;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ZoneRepository implements ZoneRepositoryInterface
{
    use ScopesByBranch;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scopeToBranchDirect(Zone::query(), $this->currentBranchId())
            ->withCount('customers')
            ->with(['agents', 'branch'])
            ->when(
                $filters['search'] ?? null,
                fn ($query, string $search) => $query->where('name', 'ILIKE', "%{$search}%")
            )
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Deliberately NOT branch-scoped: this backs zone dropdown/select
     * inputs across Customers/Agents/Payments/Manuscripts filter UIs and is
     * cached tenant-wide (App\Services\ZoneService::all()'s 'zones:all' key
     * — a single cache entry shared by every caller regardless of the
     * caller's branch fence). Branch-scoping it here without also making
     * that cache key branch-aware would leak one branch-fenced user's
     * dropdown options into another's cached response. The zone create/
     * customer create/agent create forms aren't in this feature's listed
     * scope (branches-and-locations.md section 4's repository list), so
     * this is deliberately left as future work rather than half-fixed here.
     */
    public function all(): Collection
    {
        return Zone::query()->orderBy('name')->get(['uuid', 'name', 'town']);
    }

    public function findByUuid(string $uuid): ?Zone
    {
        return $this->scopeToBranchDirect(Zone::query(), $this->currentBranchId())
            ->where('uuid', $uuid)
            ->with('branch')
            ->first();
    }

    public function create(int $branchId, ZoneData $data): Zone
    {
        return Zone::query()->create(['branch_id' => $branchId, ...$data->toAttributes()]);
    }

    public function update(Zone $zone, ZoneData $data, ?int $branchId = null): Zone
    {
        $attributes = $data->toAttributes();

        if ($branchId !== null) {
            $attributes['branch_id'] = $branchId;
        }

        $zone->update($attributes);

        return $zone;
    }

    public function delete(Zone $zone): bool
    {
        return (bool) $zone->delete();
    }
}
