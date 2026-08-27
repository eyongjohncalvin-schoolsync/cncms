<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\BranchData;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface BranchRepositoryInterface
{
    public function paginate(int $perPage): LengthAwarePaginator;

    /**
     * All branches ordered by name — backs the branch picker on the Zone
     * create/edit forms and ZoneService's "only one branch exists" default
     * logic.
     *
     * @return Collection<int, Branch>
     */
    public function all(): Collection;

    public function findByUuid(string $uuid): ?Branch;

    public function create(BranchData $data): Branch;

    public function update(Branch $branch, BranchData $data): Branch;

    public function delete(Branch $branch): bool;
}
