<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scoped($filters)
            ->with('user')
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function scoped(array $filters): Builder
    {
        return AuditLog::query()
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
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('created_at', '<=', $to));
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
        $like = '%'.addcslashes($term, '%_\\').'%';

        $customerIds = $this->idsByName(Customer::query(), $like);
        $categoryIds = $this->idsByName(ExpenseCategory::query(), $like);
        $paymentIdsForCustomers = $customerIds->isEmpty()
            ? collect()
            : Payment::query()->whereIn('customer_id', $customerIds)->pluck('id')->map(strval(...));

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

                        if ($categoryIds->isNotEmpty()) {
                            $q3->orWhereIn('old_values->category_id', $categoryIds)
                                ->orWhereIn('new_values->category_id', $categoryIds);
                        }
                    });
            });

            if ($categoryIds->isNotEmpty()) {
                $q->orWhere(function (Builder $q2) use ($categoryIds) {
                    $q2->where('table_name', 'budgets')
                        ->where(fn (Builder $q3) => $q3
                            ->whereIn('old_values->category_id', $categoryIds)
                            ->orWhereIn('new_values->category_id', $categoryIds));
                });
            }

            if ($customerIds->isNotEmpty()) {
                $q->orWhere(function (Builder $q2) use ($customerIds) {
                    $q2->whereIn('table_name', self::CUSTOMER_FK_TABLES)
                        ->where(fn (Builder $q3) => $q3
                            ->whereIn('old_values->customer_id', $customerIds)
                            ->orWhereIn('new_values->customer_id', $customerIds));
                });
            }

            if ($paymentIdsForCustomers->isNotEmpty()) {
                $q->orWhere(function (Builder $q2) use ($paymentIdsForCustomers) {
                    $q2->where('table_name', 'payment_verifications')
                        ->where(fn (Builder $q3) => $q3
                            ->whereIn('old_values->payment_id', $paymentIdsForCustomers)
                            ->orWhereIn('new_values->payment_id', $paymentIdsForCustomers));
                });
            }
        });
    }

    /**
     * @return Collection<int, string>
     */
    private function idsByName(Builder $query, string $like): Collection
    {
        return $query->where('name', 'ilike', $like)->pluck('id')->map(strval(...));
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
