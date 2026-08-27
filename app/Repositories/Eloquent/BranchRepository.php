<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\BranchData;
use App\Models\Branch;
use App\Repositories\Contracts\BranchRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class BranchRepository implements BranchRepositoryInterface
{
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Branch::query()
            ->withCount('zones')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function all(): Collection
    {
        return Branch::query()->orderBy('name')->get(['id', 'uuid', 'name']);
    }

    public function findByUuid(string $uuid): ?Branch
    {
        return Branch::query()->where('uuid', $uuid)->first();
    }

    public function create(BranchData $data): Branch
    {
        return Branch::query()->create($data->toAttributes());
    }

    public function update(Branch $branch, BranchData $data): Branch
    {
        $branch->update($data->toAttributes());

        return $branch;
    }

    public function delete(Branch $branch): bool
    {
        return (bool) $branch->delete();
    }
}
