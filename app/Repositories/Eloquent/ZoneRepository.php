<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\ZoneData;
use App\Models\Zone;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class ZoneRepository implements ZoneRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Zone::query()
            ->withCount('customers')
            ->with('agents')
            ->when(
                $filters['search'] ?? null,
                fn ($query, string $search) => $query->where('name', 'ILIKE', "%{$search}%")
            )
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid): ?Zone
    {
        return Zone::query()->where('uuid', $uuid)->first();
    }

    public function create(ZoneData $data): Zone
    {
        return Zone::query()->create($data->toAttributes());
    }

    public function update(Zone $zone, ZoneData $data): Zone
    {
        $zone->update($data->toAttributes());

        return $zone;
    }

    public function delete(Zone $zone): bool
    {
        return (bool) $zone->delete();
    }
}
