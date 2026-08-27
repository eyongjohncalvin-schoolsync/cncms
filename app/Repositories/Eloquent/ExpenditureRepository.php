<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Expenditure;
use App\Repositories\Contracts\ExpenditureRepositoryInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ExpenditureRepository implements ExpenditureRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scoped($filters)
            ->with(['category', 'user'])
            ->latest('spent_at')
            ->paginate($perPage);
    }

    public function all(array $filters): Collection
    {
        return $this->scoped($filters)
            ->with(['category'])
            ->latest('spent_at')
            ->get();
    }

    public function findByUuid(string $uuid, array $with = []): ?Expenditure
    {
        return Expenditure::query()->with($with)->where('uuid', $uuid)->first();
    }

    public function create(int $categoryId, int $userId, array $attributes): Expenditure
    {
        return Expenditure::query()->create(['category_id' => $categoryId, 'user_id' => $userId, ...$attributes]);
    }

    public function update(Expenditure $expenditure, array $attributes): Expenditure
    {
        $expenditure->update($attributes);

        return $expenditure;
    }

    public function delete(Expenditure $expenditure): bool
    {
        return (bool) $expenditure->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function scoped(array $filters): Builder
    {
        return Expenditure::query()
            ->when($filters['category_id'] ?? null, fn (Builder $query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['user_id'] ?? null, fn (Builder $query, $userId) => $query->where('user_id', $userId))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('spent_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('spent_at', '<=', $to));
    }
}
