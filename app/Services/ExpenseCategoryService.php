<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class ExpenseCategoryService
{
    public function __construct(
        private readonly ExpenseCategoryRepositoryInterface $categories,
    ) {}

    /**
     * @return Collection<int, ExpenseCategory>
     */
    public function list(bool $onlyActive = false): Collection
    {
        return $this->categories->all($onlyActive);
    }

    public function findOrFail(string $uuid): ExpenseCategory
    {
        $category = $this->categories->findByUuid($uuid);

        if (! $category) {
            throw new ModelNotFoundException("Expense category [{$uuid}] not found.");
        }

        return $category;
    }

    public function create(array $attributes): ExpenseCategory
    {
        return $this->categories->create($attributes);
    }

    public function update(ExpenseCategory $category, array $attributes): ExpenseCategory
    {
        return $this->categories->update($category, $attributes);
    }

    public function deactivate(ExpenseCategory $category): ExpenseCategory
    {
        return $this->categories->deactivate($category);
    }
}
