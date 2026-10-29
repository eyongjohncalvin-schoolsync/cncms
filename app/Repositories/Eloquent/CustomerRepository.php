<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;
use App\Models\Zone;
use App\Repositories\Concerns\ScopesByBranch;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CustomerRepository implements CustomerRepositoryInterface
{
    use ScopesByBranch;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scopeToBranchDirect(Customer::query(), $this->currentBranchId())
            ->when(
                ! empty($filters['archived']),
                // latestManuscript: what they left owing, shown on the Archived tab (owner, 2026-10-29).
                fn ($query) => $query->onlyTrashed()->with(['archivedBy', 'latestManuscript']),
            )
            ->with('zone')
            ->when(! empty($filters['pending_activation']), fn ($query) => $query->pendingActivation())
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
        return $this->scopeToBranchDirect(Customer::query(), $this->currentBranchId())
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->with($with)
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * `bill` defaults to '0' here when the caller omitted it (a services[]
     * payload — services.md section 6 — has no raw `bill` at all). The
     * column is NOT NULL with no DB default, so the INSERT needs SOME
     * value; App\Services\CustomerService::create() always calls
     * CustomerSubscriptionService::sync() immediately after, in the same
     * transaction, which overwrites this placeholder with the real summed
     * bill before anything else ever reads it. `...$data->toAttributes()`
     * comes after so an explicitly-provided `bill` (the legacy path) still
     * wins here exactly as before.
     */
    public function create(int $zoneId, CustomerData $data): Customer
    {
        return Customer::query()->create(['zone_id' => $zoneId, 'bill' => $data->bill ?? '0', ...$data->toAttributes()]);
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

    public function activeWithLatestManuscript(int|array|null $zoneIds = null): Collection
    {
        return $this->scopeToBranchDirect(Customer::query(), $this->currentBranchId())
            ->with(['zone', 'latestManuscript'])
            ->where('status', 'active')
            // Field registrations awaiting activation are never billed,
            // so never at risk and never eligible (installation phase 3).
            ->activated()
            // An empty list (an agent covering no zone) matches nothing, never everything.
            ->when($zoneIds !== null, fn ($query) => $query->whereIn('zone_id', (array) $zoneIds))
            ->orderBy('name')
            ->get();
    }

    public function findManyByUuids(array $uuids, array $with = []): Collection
    {
        return $this->scopeToBranchDirect(Customer::query(), $this->currentBranchId())
            ->with($with)
            ->whereIn('uuid', $uuids)
            ->get();
    }

    public function withoutManuscripts(?array $uuids = null): Collection
    {
        return $this->scopeToBranchDirect(Customer::query(), $this->currentBranchId())
            ->with('zone')
            ->when($uuids !== null, fn ($query) => $query->whereIn('uuid', $uuids))
            ->whereDoesntHave('manuscripts')
            ->orderBy(Zone::query()->select('name')->whereColumn('zones.id', 'customers.zone_id'))
            ->orderBy('name')
            ->get();
    }

    public function allMatching(array $filters): Collection
    {
        return $this->scopeToBranchDirect(Customer::query(), $this->currentBranchId())
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
