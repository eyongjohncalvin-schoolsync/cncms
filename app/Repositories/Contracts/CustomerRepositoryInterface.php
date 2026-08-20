<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;
use Illuminate\Pagination\LengthAwarePaginator;

interface CustomerRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'zone_id',
     *                                         'status', 'level', 'search',
     *                                         'has_phone'.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findByUuid(string $uuid, array $with = []): ?Customer;

    public function create(int $zoneId, CustomerData $data): Customer;

    public function update(Customer $customer, CustomerData $data, ?int $zoneId = null): Customer;

    public function delete(Customer $customer): bool;
}
