<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ExpenseCategory;
use Illuminate\Support\Collection;

interface ExpenseCategoryRepositoryInterface
{
    /**
     * @return Collection<int, ExpenseCategory>
     */
    public function all(bool $onlyActive = false): Collection;

    public function findByUuid(string $uuid): ?ExpenseCategory;

    public function create(array $attributes): ExpenseCategory;

    public function update(ExpenseCategory $category, array $attributes): ExpenseCategory;

    /**
     * Soft "delete" — flips is_active to false rather than removing the
     * row, per api-spec.md section 6.6 ("not hard delete").
     */
    public function deactivate(ExpenseCategory $category): ExpenseCategory;
}
