<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Manuscript;
use App\Models\Payment;
use App\Repositories\Concerns\ScopesByBranch;
use App\Repositories\Contracts\ManuscriptRepositoryInterface;
use App\Support\BusinessTimezone;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ManuscriptRepository implements ManuscriptRepositoryInterface
{
    use ScopesByBranch;

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

    /**
     * total_customers counts every customer matching the period/zone/status
     * filters regardless of `customers.status` — it mirrors the row count of
     * the manuscript list/table this summary sits above (a disconnected/
     * passive/suspended customer still gets a manuscript row every period —
     * see ManuscriptCalculator's class doc comment on the freeze behavior —
     * so it still belongs in "how many customers does this period cover").
     *
     * total_bill/total_arrears/total_credit, by contrast, are scoped to
     * `customers.status = 'active'` ONLY (owner's explicit call, 2026-08). A
     * frozen customer's total_bill is already forced to '0.00' by
     * ManuscriptCalculator, but their total_arrears/credit still carry
     * forward untouched while frozen — that's a real balance, but it is not
     * currently-accruing, collectible-this-period money: nothing is being
     * billed against it, and (per ManuscriptCalculator's doc comment)
     * payments aren't even consumed against it while the customer stays
     * disconnected/passive/suspended. Summing it into "how much is billed/
     * owed right now" would overstate this period's real billing picture
     * with stale, dormant debt. total_collected is scoped the same way (see
     * collectedForPeriod()'s doc comment) so collection_rate — computed by
     * the caller as total_collected / total_bill — stays an apples-to-apples
     * ratio: collected-from-active over billed-to-active, not a mix of
     * active billing against payments from customers of any status.
     *
     * The active-only constraint is applied via whereHas() the same as the
     * existing zone_id/status filters, so an explicit `status` filter for a
     * NON-active value (e.g. staff viewing the disconnected list) combines
     * with it via AND — correctly yielding zero for these four figures,
     * since no customer can simultaneously be 'active' and e.g.
     * 'disconnected'. That's intentional, not a bug: this summary's
     * bill/arrears/collected figures are defined as "active customers'
     * money," full stop, independent of which status the list below is
     * currently filtered to.
     *
     * @param  array<string, mixed>  $filters  Same keys as paginate().
     * @return array{total_customers: int, total_bill: string, total_arrears: string, total_credit: string, total_collected: string}
     */
    public function aggregates(array $filters): array
    {
        $totalCustomers = (int) $this->scoped($filters)
            ->selectRaw('count(distinct customer_id) as total_customers')
            ->value('total_customers');

        $totals = $this->scoped($filters)
            ->whereHas('customer', fn (Builder $inner) => $inner->where('status', 'active'))
            ->selectRaw('coalesce(sum(total_bill), 0) as total_bill')
            ->selectRaw('coalesce(sum(total_arrears), 0) as total_arrears')
            ->selectRaw('coalesce(sum(credit), 0) as total_credit')
            ->first();

        return [
            'total_customers' => $totalCustomers,
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
        return $this->scopeToBranch(Manuscript::query(), $this->currentBranchId(), 'customer.zone')
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
     * Also constrained to `customers.status = 'active'`, matching
     * total_bill/total_arrears in aggregates() above — deliberately, not
     * incidentally. total_collected is the numerator the caller
     * (ManuscriptService::collectionRate()) divides by total_bill to get
     * collection_rate; if total_bill counts only active customers' current
     * billing but total_collected counted payments from customers of ANY
     * status, a payment from a disconnected/passive/suspended customer
     * (e.g. paying down old arrears, or a reconnection fee —
     * CustomerStatusService::reconnectOne()) would inflate the numerator
     * against a denominator that never included that customer's billing at
     * all, silently pushing collection_rate above what was actually
     * collected against what was actually billed this period — possibly
     * over 100%. Scoping both sides to the same active population keeps the
     * ratio meaningful: money collected from active customers over money
     * billed to active customers.
     *
     * @param  array<string, mixed>  $filters
     */
    private function collectedForPeriod(array $filters): string
    {
        $start = Carbon::createFromFormat('Y-m', $filters['period'], BusinessTimezone::WAT)
            ->startOfMonth()
            ->setTimezone('UTC');
        $end = Carbon::createFromFormat('Y-m', $filters['period'], BusinessTimezone::WAT)
            ->endOfMonth()
            ->setTimezone('UTC');

        $total = $this->scopeToBranch(Payment::query(), $this->currentBranchId(), 'customer.zone')
            ->where('verification_status', 'verified')
            ->whereBetween('created_at', [$start, $end])
            ->whereHas('customer', fn (Builder $inner) => $inner->where('status', 'active'))
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
