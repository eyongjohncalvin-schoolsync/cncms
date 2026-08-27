<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\ZoneData;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ZoneRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'search'.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * All zones ordered by name, minimal columns (uuid, name, town) — backs
     * dropdown/select inputs. Callers needing more columns/relations should
     * query Zone directly instead of adding weight to this hot path.
     *
     * @return Collection<int, Zone>
     */
    public function all(): Collection;

    public function findByUuid(string $uuid): ?Zone;

    public function create(int $branchId, ZoneData $data): Zone;

    public function update(Zone $zone, ZoneData $data, ?int $branchId = null): Zone;

    public function delete(Zone $zone): bool;
}
