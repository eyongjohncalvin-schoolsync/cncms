<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\ZoneData;
use App\Models\Scopes\BranchScope;
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
     * Every zone of the company, deliberately NOT branch-scoped: it is cached
     * once for the whole company (App\Services\ZoneService::all()'s
     * 'zones:all' key) and ZoneService narrows it to the caller's branch, so
     * one fenced user's list can never be served to another.
     */
    public function all(): Collection
    {
        return Zone::query()->withoutGlobalScope(BranchScope::class)->orderBy('name')->get(['id', 'uuid', 'name', 'town', 'branch_id']);
    }

    public function findByUuid(string $uuid): ?Zone
    {
        return $this->scopeToBranchDirect(Zone::query(), $this->currentBranchId())
            ->where('uuid', $uuid)
            ->with('branch')
            ->first();
    }

    public function forBulkAction(array $filters, ?array $uuids, int $limit): Collection
    {
        return $this->scopeToBranchDirect(Zone::query(), $this->currentBranchId())
            ->withCount(['customers', 'agents'])
            ->when(
                $uuids !== null,
                fn ($query) => $query->whereIn('uuid', $uuids),
                fn ($query) => $query->when(
                    $filters['search'] ?? null,
                    fn ($query, string $search) => $query->where('name', 'ILIKE', "%{$search}%")
                ),
            )
            ->orderBy('name')
            ->limit($limit)
            ->get();
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
