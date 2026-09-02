<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;
use App\Repositories\Concerns\ScopesByBranch;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CustomerRepository implements CustomerRepositoryInterface
{
    use ScopesByBranch;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scopeToBranch(Customer::query(), $this->currentBranchId())
            ->when(
                ! empty($filters['archived']),
                fn ($query) => $query->onlyTrashed()->with('archivedBy'),
            )
            ->with('zone')
            // Drives the "Archive customer" vs "Delete row" choice on the
            // list without an N+1 — one EXISTS subquery per relation per row.
            ->withExists(['payments', 'manuscripts', 'messages'])
            ->when($filters['zone_id'] ?? null, fn ($query, $zoneId) => $query->where('zone_id', $zoneId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['level'] ?? null, fn ($query, $level) => $query->where('level', $level))
            ->when(
                $filters['has_phone'] ?? null,
                fn ($query, bool $hasPhone) => $hasPhone
                    ? $query->whereNotNull('phone')
                    : $query->whereNull('phone')
            )
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid, array $with = [], bool $withTrashed = false): ?Customer
    {
        return $this->scopeToBranch(Customer::query(), $this->currentBranchId())
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->with($with)
            ->where('uuid', $uuid)
            ->first();
    }

    public function create(int $zoneId, CustomerData $data): Customer
    {
        return Customer::query()->create(['zone_id' => $zoneId, ...$data->toAttributes()]);
    }

    public function update(Customer $customer, CustomerData $data, ?int $zoneId = null): Customer
    {
        $attributes = $data->toAttributes();

        if ($zoneId !== null) {
            $attributes['zone_id'] = $zoneId;
        }

        $customer->update($attributes);

        return $customer;
    }

    public function delete(Customer $customer): bool
    {
        // forceDelete, not delete: Customer uses SoftDeletes now, and this
        // path is only reached for a zero-history junk row that should
        // actually leave the table rather than become an archived tombstone.
        return (bool) $customer->forceDelete();
    }

    public function archive(Customer $customer, int $actorId, string $reason): void
    {
        $customer->archived_by = $actorId;
        $customer->archived_reason = $reason;
        $customer->save();

        $customer->delete();
    }

    public function restore(Customer $customer): void
    {
        $customer->archived_by = null;
        $customer->archived_reason = null;
        $customer->restore();
    }

    public function updateStatus(Customer $customer, array $attributes): Customer
    {
        $customer->update($attributes);

        return $customer;
    }

    public function activeWithLatestManuscript(?int $zoneId = null): Collection
    {
        return $this->scopeToBranch(Customer::query(), $this->currentBranchId())
            ->with(['zone', 'latestManuscript'])
            ->where('status', 'active')
            ->when($zoneId, fn ($query, $zoneId) => $query->where('zone_id', $zoneId))
            ->orderBy('name')
            ->get();
    }

    public function findManyByUuids(array $uuids): Collection
    {
        return $this->scopeToBranch(Customer::query(), $this->currentBranchId())
            ->whereIn('uuid', $uuids)
            ->get();
    }

    public function allMatching(array $filters): Collection
    {
        return $this->scopeToBranch(Customer::query(), $this->currentBranchId())
            ->when($filters['zone_id'] ?? null, fn ($query, $zoneId) => $query->where('zone_id', $zoneId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['level'] ?? null, fn ($query, $level) => $query->where('level', $level))
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();
    }
}
