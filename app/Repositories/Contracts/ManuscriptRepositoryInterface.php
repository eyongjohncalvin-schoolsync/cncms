<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Manuscript;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ManuscriptRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'period' (required), 'zone_id', 'status',
     *                                         'search' (customer name ILIKE / phone LIKE, partial).
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Unpaginated variant of paginate(), for exports that need the full
     * matching result set (e.g. the manuscript register PDF) rather than a
     * single page.
     *
     * @param  array<string, mixed>  $filters  Same keys as paginate().
     * @return Collection<int, Manuscript>
     */
    public function all(array $filters): Collection;

    /**
     * total_customers counts every customer matching the filters regardless
     * of status. total_bill/total_arrears/total_credit/total_collected are
     * scoped to `customers.status = 'active'` ONLY — a disconnected/passive/
     * suspended customer's frozen, non-accruing balance is real but not
     * currently-collectible money, so it's excluded from these four figures
     * (and therefore from collection_rate, derived from total_collected/
     * total_bill by the caller). See the Eloquent implementation's doc
     * comment for the full reasoning.
     *
     * @param  array<string, mixed>  $filters  Same keys as paginate().
     * @return array{total_customers: int, total_bill: string, total_arrears: string, total_credit: string, total_collected: string}
     */
    public function aggregates(array $filters): array;

    /**
     * @return Collection<int, Manuscript>
     */
    public function historyForCustomer(int $customerId): Collection;

    /**
     * The single manuscript for a customer in a given period, if one has
     * been calculated. Used by bill printing to resolve a specific period
     * rather than always the latest.
     */
    public function forCustomerAndPeriod(int $customerId, string $period): ?Manuscript;
}
