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
            ->with(['category', 'user', 'voucher'])
            ->latest('spent_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function all(array $filters): Collection
    {
        return $this->scoped($filters)
            ->with(['category', 'user', 'voucher'])
            ->latest('spent_at')
            ->latest('id')
            ->get();
    }

    public function findByUuid(string $uuid, array $with = []): ?Expenditure
    {
        return Expenditure::query()->with($with)->where('uuid', $uuid)->first();
    }

    public function update(Expenditure $expenditure, array $attributes): Expenditure
    {
        $expenditure->update($attributes);

        return $expenditure;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function scoped(array $filters): Builder
    {
        return Expenditure::query()
            ->when($filters['category_id'] ?? null, fn (Builder $query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['user_id'] ?? null, fn (Builder $query, $userId) => $query->where('user_id', $userId))
            ->when($filters['source'] ?? null, fn (Builder $query, $source) => $query->where('source', $source))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('spent_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('spent_at', '<=', $to));
    }
}
