<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuditLogRepository implements AuditLogRepositoryInterface
{
    /**
     * Audited tables that carry their OWN `name` column, so it's present
     * verbatim in the JSONB snapshot on every row (create writes it to
     * new_values, update writes it to both old_values and new_values,
     * delete writes it to old_values) — searchable straight off the
     * snapshot with no live join, which is what keeps a deleted record's
     * history findable by name.
     *
     * @var array<int, string>
     */
    private const NAME_FIELD_TABLES = ['customers', 'agents', 'zones', 'expense_categories', 'companies'];

    /**
     * Audited tables with no name of their own that instead reference a
     * customer directly via a `customer_id` column.
     *
     * @var array<int, string>
     */
    private const CUSTOMER_FK_TABLES = ['payments', 'manuscripts', 'messages'];

    /** Shorter search terms are ignored (they match nearly every name). */
    public const int MIN_SEARCH_LENGTH = 3;

    /** The total shown stops here: counting a whole, ever-growing table on every page view is the cost. */
    public const int COUNT_CAP = 10_000;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage() ?: 1;

        // Counts at most COUNT_CAP matching rows (a LIMITed subquery), not every row in the table.
        $total = (int) DB::query()->fromSub($this->scoped($filters)->select('id')->limit(self::COUNT_CAP)->toBase(), 'capped')->count();

        $items = $this->scoped($filters)
            ->with(['user', 'impersonator'])
            ->latest('created_at')
            ->forPage($page, $perPage)
            ->get();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function scoped(array $filters): Builder
    {
        return AuditLog::query()
            // Branches v2: a fenced viewer sees their branch's changes only — never
            // company-wide rows (settings, roles) or another branch's.
            ->when(TenantContext::currentBranchId(), fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['table_name'] ?? null, fn (Builder $query, string $table) => $query->where('table_name', $table))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            // Power-user/debugging escape hatch: an exact record_uuid still
            // narrows results when known, but the primary UI no longer asks
            // for one — see applySearch() for the name-based replacement.
            ->when($filters['record_uuid'] ?? null, fn (Builder $query, string $uuid) => $query->where('record_uuid', $uuid))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->applySearch($query, $search))
            ->when(
                $filters['user_uuid'] ?? null,
                fn (Builder $query, string $userUuid) => $query->where('user_id', $this->resolveUserId($userUuid))
            )
            // Day bounds in business time compared directly with created_at
            // (not created_at::date), so the created_at index serves them.
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->where('created_at', '>=', Carbon::parse($from, BusinessTimezone::WAT)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->where('created_at', '<', Carbon::parse($to, BusinessTimezone::WAT)->addDay()->startOfDay()->utc()));
    }

    /**
     * Name-based search replacing the old record_uuid-only filter as the
     * PRIMARY way to find an audit trail. No human can be expected to
     * memorize a UUID, so this resolves the term against whichever field
     * actually identifies each table_name's record:
     *
     *  - NAME_FIELD_TABLES (customers/agents/zones/expense_categories/
     *    companies): matched directly against the JSONB `name` snapshot.
     *    Deliberately NOT a live join against the current table — matching
     *    the snapshot means a DELETED record's audit trail stays
     *    searchable by the name it had at the time, which a live join
     *    would lose entirely.
     *  - expenditures: matched against its own `description` snapshot,
     *    plus its category's current name (categories are rarely deleted
     *    and expenditures have no name of their own to fall back on).
     *  - budgets: no name of its own either — matched via its category's
     *    current name, same as expenditures.
     *  - CUSTOMER_FK_TABLES (payments/manuscripts/messages): these hold
     *    only a `customer_id` in their own snapshot, never the customer's
     *    name, so there is no snapshot-only option here — resolved via the
     *    LIVE `customers` table by name, then matched by id. Known gap:
     *    if the customer was later deleted, their payments/manuscripts/
     *    messages become unsearchable by name through this branch (the
     *    customer's OWN audit trail remains searchable via the branch
     *    above). Denormalizing the customer name into every one of these
     *    tables' audit snapshots would close that gap but requires
     *    touching the write path (Auditable/AuditableObserver) for a
     *    small, single-tenant dataset — not justified here.
     *  - payment_verifications: no customer_id of its own either; chains
     *    payment_id -> payments.customer_id -> customers.name, same live
     *    resolution and same known gap.
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        // Too short to mean anything: one or two letters match most names.
        if (mb_strlen(trim($term)) < self::MIN_SEARCH_LENGTH) {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        // Subqueries, not id lists: a common name used to expand into
        // thousands of literal ids (and every payment of those customers).
        $customerIds = $this->idsByName(Customer::query(), $like);
        $categoryIds = $this->idsByName(ExpenseCategory::query(), $like);
        $paymentIdsForCustomers = Payment::query()->whereIn('customer_id', Customer::query()->where('name', 'ilike', $like)->select('id'))->selectRaw('id::text');

        return $query->where(function (Builder $q) use ($like, $customerIds, $categoryIds, $paymentIdsForCustomers) {
            $q->where(function (Builder $q2) use ($like) {
                $q2->whereIn('table_name', self::NAME_FIELD_TABLES)
                    ->where(fn (Builder $q3) => $q3
                        ->where('old_values->name', 'ilike', $like)
                        ->orWhere('new_values->name', 'ilike', $like));
            });

            $q->orWhere(function (Builder $q2) use ($like, $categoryIds) {
                $q2->where('table_name', 'expenditures')
                    ->where(function (Builder $q3) use ($like, $categoryIds) {
                        $q3->where('old_values->description', 'ilike', $like)
                            ->orWhere('new_values->description', 'ilike', $like);

                        $q3->orWhereIn('old_values->category_id', $categoryIds)
                            ->orWhereIn('new_values->category_id', $categoryIds);
                    });
            });

            $q->orWhere(function (Builder $q2) use ($categoryIds) {
                $q2->where('table_name', 'budgets')
                    ->where(fn (Builder $q3) => $q3
                        ->whereIn('old_values->category_id', $categoryIds)
                        ->orWhereIn('new_values->category_id', $categoryIds));
            });

            $q->orWhere(function (Builder $q2) use ($customerIds) {
                $q2->whereIn('table_name', self::CUSTOMER_FK_TABLES)
                    ->where(fn (Builder $q3) => $q3
                        ->whereIn('old_values->customer_id', $customerIds)
                        ->orWhereIn('new_values->customer_id', $customerIds));
            });

            $q->orWhere(function (Builder $q2) use ($paymentIdsForCustomers) {
                $q2->where('table_name', 'payment_verifications')
                    ->where(fn (Builder $q3) => $q3
                        ->whereIn('old_values->payment_id', $paymentIdsForCustomers)
                        ->orWhereIn('new_values->payment_id', $paymentIdsForCustomers));
            });
        });
    }

    /**
     * The ids (as text, like the JSON snapshot values) of rows named like
     * $like — a subquery, run inside the audit query.
     */
    private function idsByName(Builder $query, string $like): Builder
    {
        return $query->where('name', 'ilike', $like)->selectRaw('id::text');
    }

    /**
     * Resolves the external-facing user UUID to the internal id the
     * audit_logs.user_id column stores — same UUID->id resolution shape as
     * App\Services\PaymentService::resolveCustomerId. Returns 0 (matches no
     * row) rather than null for an unknown UUID, so the filter narrows the
     * result set to nothing instead of silently being ignored.
     */
    private function resolveUserId(string $userUuid): int
    {
        return User::query()->where('uuid', $userUuid)->value('id') ?? 0;
    }
}
