<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Expenditure;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read side of expenditures plus in-place edits. There is deliberately no
 * create() or delete(): since docs/plans/expenditures-and-spending-controls.md
 * (E1) only App\Services\VoucherService creates expenditures, and nothing is
 * ever hard-deleted (a void keeps the row).
 */
interface ExpenditureRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'category_id',
     *                                         'user_id', 'from', 'to', 'source', 'status'.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Unpaginated — the export and full-period aggregation.
     *
     * @param  array<string, mixed>  $filters  Same keys as paginate().
     * @return Collection<int, Expenditure>
     */
    public function all(array $filters): Collection;

    public function findByUuid(string $uuid, array $with = []): ?Expenditure;

    public function update(Expenditure $expenditure, array $attributes): Expenditure;
}
