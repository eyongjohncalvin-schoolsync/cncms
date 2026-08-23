<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Manuscript;
use App\Models\Payment;
use App\Repositories\Contracts\ManuscriptRepositoryInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ManuscriptRepository implements ManuscriptRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scoped($filters)
            ->with('customer.zone')
            ->orderBy('customer_id')
            ->paginate($perPage);
    }

    public function all(array $filters): Collection
    {
        return $this->scoped($filters)
            ->with('customer.zone')
            ->orderBy('customer_id')
            ->get();
    }

    public function aggregates(array $filters): array
    {
        $totals = $this->scoped($filters)
            ->selectRaw('count(distinct customer_id) as total_customers')
            ->selectRaw('coalesce(sum(total_bill), 0) as total_bill')
            ->selectRaw('coalesce(sum(total_arrears), 0) as total_arrears')
            ->selectRaw('coalesce(sum(credit), 0) as total_credit')
            ->first();

        return [
            'total_customers' => (int) $totals->total_customers,
            'total_bill' => (string) $totals->total_bill,
            'total_arrears' => (string) $totals->total_arrears,
            'total_credit' => (string) $totals->total_credit,
            'total_collected' => $this->collectedForPeriod($filters),
        ];
    }

    public function historyForCustomer(int $customerId): Collection
    {
        return Manuscript::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('period')
            ->get();
    }

    public function forCustomerAndPeriod(int $customerId, string $period): ?Manuscript
    {
        return Manuscript::query()
            ->where('customer_id', $customerId)
            ->where('period', $period)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function scoped(array $filters): Builder
    {
        return Manuscript::query()
            ->where('period', $filters['period'])
            ->when(
                $filters['zone_id'] ?? null,
                fn (Builder $query, $zoneId) => $query->whereHas('customer', fn (Builder $inner) => $inner->where('zone_id', $zoneId))
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status) => $query->whereHas('customer', fn (Builder $inner) => $inner->where('status', $status))
            );
    }

    /**
     * Verified payments recorded during the period's calendar month, for
     * customers matching the same zone/status filters. Manuscripts don't
     * persist the income figure the billing engine computed (see
     * ManuscriptCalculator), so "collected" is derived independently here
     * rather than read back off the manuscripts row.
     *
     * @param  array<string, mixed>  $filters
     */
    private function collectedForPeriod(array $filters): string
    {
        $start = Carbon::createFromFormat('Y-m', $filters['period'])->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $total = Payment::query()
            ->where('verification_status', 'verified')
            ->whereBetween('created_at', [$start, $end])
            ->when(
                $filters['zone_id'] ?? null,
                fn (Builder $query, $zoneId) => $query->whereHas('customer', fn (Builder $inner) => $inner->where('zone_id', $zoneId))
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status) => $query->whereHas('customer', fn (Builder $inner) => $inner->where('status', $status))
            )
            ->sum('amount');

        return (string) $total;
    }
}
