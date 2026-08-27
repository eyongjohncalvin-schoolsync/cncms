<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\ExpenseCategory;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use Illuminate\Support\Collection;

class ExpenseCategoryRepository implements ExpenseCategoryRepositoryInterface
{
    public function all(bool $onlyActive = false): Collection
    {
        return ExpenseCategory::query()
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function findByUuid(string $uuid): ?ExpenseCategory
    {
        return ExpenseCategory::query()->where('uuid', $uuid)->first();
    }

    public function create(array $attributes): ExpenseCategory
    {
        return ExpenseCategory::query()->create($attributes);
    }

    public function update(ExpenseCategory $category, array $attributes): ExpenseCategory
    {
        $category->update($attributes);

        return $category;
    }

    public function deactivate(ExpenseCategory $category): ExpenseCategory
    {
        $category->update(['is_active' => false]);

        return $category;
    }
}
