<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerRepository implements CustomerRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Customer::query()
            ->with('zone')
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

    public function findByUuid(string $uuid, array $with = []): ?Customer
    {
        return Customer::query()->with($with)->where('uuid', $uuid)->first();
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
        return (bool) $customer->delete();
    }
}
