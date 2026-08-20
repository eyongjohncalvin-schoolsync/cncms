<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\ZoneData;
use App\Models\Zone;
use Illuminate\Pagination\LengthAwarePaginator;

interface ZoneRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'search'.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findByUuid(string $uuid): ?Zone;

    public function create(ZoneData $data): Zone;

    public function update(Zone $zone, ZoneData $data): Zone;

    public function delete(Zone $zone): bool;
}
