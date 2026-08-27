<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Expenditure;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ExpenditureRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'category_id',
     *                                         'user_id', 'from', 'to'.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Unpaginated — used by the P&L dashboard for full-period aggregation.
     *
     * @param  array<string, mixed>  $filters  Same keys as paginate().
     * @return Collection<int, Expenditure>
     */
    public function all(array $filters): Collection;

    public function findByUuid(string $uuid, array $with = []): ?Expenditure;

    public function create(int $categoryId, int $userId, array $attributes): Expenditure;

    public function update(Expenditure $expenditure, array $attributes): Expenditure;

    public function delete(Expenditure $expenditure): bool;
}
