<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly ZoneRepositoryInterface $zones,
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (! empty($filters['zone_uuid'])) {
            $filters['zone_id'] = $this->resolveZoneId($filters['zone_uuid']);
        }

        return $this->customers->paginate($filters, $perPage);
    }

    public function findOrFail(string $uuid): Customer
    {
        $customer = $this->customers->findByUuid($uuid, ['zone', 'latestManuscript']);

        if (! $customer) {
            throw new ModelNotFoundException("Customer [{$uuid}] not found.");
        }

        $customer->setRelation(
            'payments',
            $customer->payments()->latest('created_at')->limit(5)->get()
        );

        return $customer;
    }

    public function create(CustomerData $data): Customer
    {
        $zoneId = $this->resolveZoneId($data->zoneUuid);

        $customer = $this->customers->create($zoneId, $data);

        return $customer->load('zone');
    }

    public function update(Customer $customer, CustomerData $data): Customer
    {
        $zoneId = $data->zoneUuid !== null ? $this->resolveZoneId($data->zoneUuid) : null;

        $customer = $this->customers->update($customer, $data, $zoneId);

        return $customer->load('zone');
    }

    public function delete(Customer $customer): void
    {
        $this->customers->delete($customer);
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
}
