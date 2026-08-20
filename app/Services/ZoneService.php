<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ZoneData;
use App\Models\Zone;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class ZoneService
{
    public function __construct(
        private readonly ZoneRepositoryInterface $zones,
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->zones->paginate($filters, $perPage);
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
        return $this->zones->create($data);
    }

    public function update(Zone $zone, ZoneData $data): Zone
    {
        return $this->zones->update($zone, $data);
    }

    public function delete(Zone $zone): void
    {
        $this->zones->delete($zone);
    }
}
