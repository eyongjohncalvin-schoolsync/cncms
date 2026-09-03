<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    /**
     * Deliberately does NOT constructor-inject App\Support\TenantContext,
     * even though several methods below need the caller's branch fence for
     * cache-key isolation — this Service is resolved directly via
     * app(CustomerService::class) (through CustomerImportService) by at
     * least one test (CustomerImportSeedsManuscriptArrearsTest) outside any
     * tenant HTTP request, where no TenantContext has been bound. A hard
     * constructor dependency there throws instead of importing. Methods use
     * TenantContext::currentBranchId() instead, which resolves defensively
     * and falls back to "unrestricted" — see that method's doc comment.
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly ZoneRepositoryInterface $zones,
        private readonly CustomerSubscriptionService $subscriptions,
    ) {}

    /**
     * Customers are written frequently (new customers, payments/manuscripts
     * affecting derived fields), so precise invalidation of every
     * filter/page combination isn't practical. A short TTL is the pragmatic
     * tradeoff: results can be up to ~30s stale, which is acceptable for a
     * list view.
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (! empty($filters['zone_uuid'])) {
            $filters['zone_id'] = $this->resolveZoneId($filters['zone_uuid']);
        }

        // The branch fence (App\Support\TenantContext::$branchId) is baked
        // into every key below rather than left out — App\Repositories\
        // Eloquent\CustomerRepository's queries are branch-scoped, so two
        // callers with identical $filters but different branch fences can
        // legitimately get different rows. Without this, whichever caller's
        // request lands first would poison the cache for every caller after
        // it (a cross-branch super's result served back to a branch-fenced
        // manager, or vice versa) — a real cross-branch data leak, not just
        // staleness.
        $cacheKey = 'customers:list:'.(TenantContext::currentBranchId() ?? 'all').':'
            .md5(json_encode([$filters, $perPage, request()->query('page', 1)]));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(30),
            fn (): LengthAwarePaginator => $this->customers->paginate($filters, $perPage),
        );
    }

    public function findOrFail(string $uuid, bool $withTrashed = false): Customer
    {
        $customer = Cache::remember(
            "customers:show:{$uuid}:".(TenantContext::currentBranchId() ?? 'all').($withTrashed ? ':trashed' : ''),
            now()->addSeconds(60),
            function () use ($uuid, $withTrashed): Customer {
                $customer = $this->customers->findByUuid(
                    $uuid,
                    ['zone', 'latestManuscript'],
                    $withTrashed,
                );

                if (! $customer) {
                    throw new ModelNotFoundException("Customer [{$uuid}] not found.");
                }

                $customer->setRelation(
                    'payments',
                    $customer->payments()->latest('created_at')->limit(5)->get()
                );

                return $customer;
            },
        );

        // `subscriptions.service`/`subscriptions.serviceVariant` are
        // deliberately loaded FRESH here, every call, never inside the
        // Cache::remember above. Found 2026-09-03 during mobile QA: this
        // app's CACHE_STORE is 'database' (Postgres), and PHP's serialize()
        // of an object encodes protected/private property names with
        // embedded NUL (\0) bytes — a byte Postgres `text`/`varchar` columns
        // cannot hold. Caching a Customer with subscriptions eager-loaded
        // (CustomerSubscription extends Pivot, itself carrying
        // protected/private properties) silently corrupts the stored blob;
        // reading it back on the next cache HIT produces
        // __PHP_Incomplete_Class objects in place of CustomerSubscription
        // rows, and CustomerResource::toArray()'s services mapping then
        // fatals with a TypeError — reproduced live: a cold GET succeeds
        // (cache MISS, computes fresh), the very next identical GET within
        // the 60s TTL 500s (cache HIT, corrupted unserialize), every time.
        // This hit both the web Customer Show page and every mobile screen
        // that calls GET /customers/{uuid} (Customer Detail's live refresh,
        // customer-edit/[uuid].tsx, reconnect/disconnect/adjust-arrears).
        // Excluding subscriptions from what gets cached — `zone`/
        // `latestManuscript` have no such nested Pivot model and were
        // already caching safely before today — and loading them
        // separately on every call (uncached; a small extra query, but
        // avoids a data-integrity/serialization risk entirely) closes this
        // without touching the wider 'database' cache store used
        // everywhere else in this class.
        if (! $customer->relationLoaded('subscriptions')) {
            $customer->load('subscriptions.service', 'subscriptions.serviceVariant');
        }

        return $customer;
    }

    /**
     * The customer row and its service subscriptions are written in ONE
     * transaction — `customers.bill` is a cached projection of
     * `sum(customer_service.price)` and must never be left out of sync with
     * the pivot (services.md section 2). A request with no `services` key
     * (CustomerData::$services === null) defaults a brand-new customer to
     * the single default service, priced at the legacy `bill` field if the
     * caller sent one — StoreCustomerRequest accepts either `bill` or
     * `services` (services.md section 6; CustomerImportService's xlsx path
     * deliberately keeps sending raw `bill` forever, not just during a
     * transition), so a raw `bill` must keep working exactly as before, not
     * get silently reset to the service's catalogue price by the recompute
     * below.
     *
     * Eager-loads `subscriptions.service`/`subscriptions.serviceVariant` on
     * the way out so CustomerResource's `services` field (mobile app) and
     * the web controller's own shapeCustomer() both see it immediately,
     * without a second round trip right after create.
     */
    public function create(CustomerData $data): Customer
    {
        $zoneId = $this->resolveZoneId($data->zoneUuid);

        return DB::transaction(function () use ($zoneId, $data): Customer {
            $customer = $this->customers->create($zoneId, $data);

            $selections = $data->services ?? [$this->subscriptions->defaultSelection($data->bill)];
            $this->subscriptions->sync($customer, $selections);

            return $customer->load(['zone', 'subscriptions.service', 'subscriptions.serviceVariant']);
        });
    }

    public function update(Customer $customer, CustomerData $data): Customer
    {
        $zoneId = $data->zoneUuid !== null ? $this->resolveZoneId($data->zoneUuid) : null;

        $customer = DB::transaction(function () use ($customer, $data, $zoneId): Customer {
            $customer = $this->customers->update($customer, $data, $zoneId);

            // null = leave subscriptions alone (bulk bill update, a status
            // change, any partial edit that didn't touch the services block).
            if ($data->services !== null) {
                $this->subscriptions->sync($customer, $data->services);
            }

            return $customer;
        });

        $this->forgetShowCache($customer->uuid);

        return $customer->load(['zone', 'subscriptions.service', 'subscriptions.serviceVariant']);
    }

    /**
     * Hard delete — reserved for a customer with ZERO billing history (a
     * junk import row, a mistyped duplicate, a test record never billed).
     * The controller only reaches this path when hasBillingHistory() is
     * false; a customer with any payment/manuscript/message is archived
     * instead (archive() below), never destroyed.
     *
     * `payments.customer_id` has always used restrictOnDelete(), and
     * `manuscripts.customer_id`/`messages.customer_id` were fixed to match
     * (2026_08_26_030000_restrict_delete_on_manuscripts_and_messages_
     * customer_id.php) — the database refuses to delete a customer with any
     * such history. That refusal is the backstop if the UI guard is ever
     * bypassed: it surfaces here as a QueryException, translated into a
     * friendly ValidationException (that now points at archiving) rather
     * than a raw SQL error, mirroring App\Services\BranchService::delete()'s
     * pattern for the same restrictOnDelete() situation on zones.branch_id.
     *
     * The delete is wrapped in DB::transaction() for the same reason as
     * BranchService::delete(): Postgres refuses every further statement on a
     * transaction after an unhandled error until it's rolled back, which
     * would otherwise break the ->count() lookups below (and, in tests, the
     * outer per-test transaction).
     *
     * `$customer->forceDelete()` (not `delete()`): Customer now uses
     * SoftDeletes, and a genuinely-empty junk row should actually leave the
     * table, not become an archived tombstone.
     */
    public function delete(Customer $customer): void
    {
        try {
            DB::transaction(fn () => $this->customers->delete($customer));
        } catch (QueryException $e) {
            if (! $this->isForeignKeyViolation($e)) {
                throw $e;
            }

            $paymentCount = $customer->payments()->count();
            $manuscriptCount = $customer->manuscripts()->count();
            $messageCount = $customer->messages()->count();

            $parts = array_filter([
                $paymentCount > 0 ? "{$paymentCount} payment(s)" : null,
                $manuscriptCount > 0 ? "{$manuscriptCount} manuscript(s)" : null,
                $messageCount > 0 ? "{$messageCount} message(s)" : null,
            ]);

            $detail = $parts === [] ? 'billing history' : implode(', ', $parts);

            throw ValidationException::withMessages([
                'customer' => ["Cannot delete {$customer->name} — this customer has billing history ({$detail}). Archive the customer instead to keep the history for auditing."],
            ]);
        }

        $this->forgetShowCache($customer->uuid);
    }

    /**
     * Whether this customer has any billing history that must be preserved —
     * the signal the UI uses to offer "Archive customer" instead of
     * "Delete row", and the guard the controller checks before delete().
     * Any payment, manuscript, or message counts; a customer with none is a
     * junk/never-billed row that can be hard-deleted with nothing lost.
     */
    public function hasBillingHistory(Customer $customer): bool
    {
        return $customer->payments()->exists()
            || $customer->manuscripts()->exists()
            || $customer->messages()->exists();
    }

    /**
     * Archive (soft-delete) a customer — the terminal state for a real
     * customer who has left. Every payment/manuscript/message row stays
     * physically in place (all four customer_id FKs are restrictOnDelete()
     * and simply become unreachable); the SoftDeletes global scope drops
     * the customer from active lists, the dashboard, manuscript runs, and
     * disconnection-eligibility scans. Fully reversible via restore().
     *
     * `archived_by`/`archived_reason` are persisted with an explicit save()
     * FIRST, so the Auditable trait records one 'update' row that
     * AuditLogService::summarizeCustomer() renders as "Archived customer:
     * NAME" (the reason is in new_values). The subsequent soft delete()
     * fires its own `deleted` event, but AuditableObserver skips the audit
     * row for a soft delete (not a forceDelete) — the 'update' row above
     * already IS the archive record, and a second "deleted" row would just
     * be noise.
     */
    public function archive(Customer $customer, int $actorId, string $reason): void
    {
        DB::transaction(fn () => $this->customers->archive($customer, $actorId, $reason));

        $this->forgetShowCache($customer->uuid);
    }

    /**
     * Bring an archived customer back — they reappear in the register and
     * the next manuscript run resumes from wherever their ledger was left.
     * Clearing `archived_by`/`archived_reason` before restore() means the
     * single save() inside SoftDeletes::restore() persists all three column
     * changes (deleted_at → null, archived_by → null, archived_reason →
     * null) in one `updated` event — AuditLogService::summarizeCustomer()
     * reads the archived_by → null transition as "Restored customer: NAME".
     */
    public function restore(Customer $customer): void
    {
        DB::transaction(fn () => $this->customers->restore($customer));

        $this->forgetShowCache($customer->uuid);
    }

    private function isForeignKeyViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23001', '23503'], true);
    }

    /**
     * findOrFail()'s cache key is branch-suffixed (see its doc comment), so
     * a single uuid can be cached under several keys — one per distinct
     * branch fence that has looked it up. Only this actor's own key and the
     * unrestricted ('all') key are explicitly forgotten here; any other
     * branch-fenced caller's cached copy is bounded by findOrFail()'s 60s
     * TTL instead, the same staleness tradeoff already accepted for list().
     */
    private function forgetShowCache(string $uuid): void
    {
        foreach (['', ':trashed'] as $suffix) {
            Cache::forget("customers:show:{$uuid}:".(TenantContext::currentBranchId() ?? 'all').$suffix);
            Cache::forget("customers:show:{$uuid}:all".$suffix);
        }
    }

    private function resolveZoneId(?string $zoneUuid): int
    {
        if (! $zoneUuid) {
            throw ValidationException::withMessages(['zone_uuid' => ['The zone_uuid field is required.']]);
        }

        $zone = $this->zones->findByUuid($zoneUuid);

        if (! $zone) {
            throw ValidationException::withMessages(['zone_uuid' => ['The selected zone does not exist.']]);
        }

        return $zone->id;
    }

    /**
     * Dry-run counterpart of bulkUpdateBill() — computes what WOULD change
     * for every matched customer without writing anything, so the office
     * worker can see a real current->new table before committing an annual
     * price adjustment across dozens of customers. Both this method and
     * bulkUpdateBill() delegate to the same planBulkBillUpdate() (and, in
     * turn, the same computeAdjustedBill() bcmath), so the number a manager
     * previews is guaranteed to be the exact number that gets saved — the
     * two code paths cannot drift apart.
     *
     * @param  string[]|null  $customerUuids  Explicit selection. When present
     *                                        and non-empty, takes priority
     *                                        over $filters entirely.
     * @param  array<string, mixed>  $filters  Filter-descriptor selection
     *                                         (zone_uuid/level/status/search —
     *                                         the same shape list() accepts),
     *                                         used only when $customerUuids
     *                                         is null/empty.
     * @param  string  $mode  One of: set, increase_fixed, increase_percent.
     * @param  string  $value  Decimal amount (set/increase_fixed) or
     *                         percentage (increase_percent) — may be
     *                         negative for a decrease.
     * @return array{
     *     preview: list<array{customer_uuid: string, name: string, current_bill: string, new_bill: string}>,
     *     skipped: array<string, string>,
     * }
     */
    public function previewBulkBillUpdate(?array $customerUuids, array $filters, string $mode, string $value): array
    {
        $plan = $this->planBulkBillUpdate($customerUuids, $filters, $mode, $value);

        return [
            'preview' => $plan->filter(fn (array $row): bool => $row['new_bill'] !== null)
                ->map(fn (array $row): array => [
                    'customer_uuid' => $row['customer']->uuid,
                    'name' => $row['customer']->name,
                    'current_bill' => (string) $row['customer']->bill,
                    'new_bill' => $row['new_bill'],
                ])
                ->values()
                ->all(),
            'skipped' => $plan->filter(fn (array $row): bool => $row['new_bill'] === null)
                ->mapWithKeys(fn (array $row): array => [$row['customer']->uuid => $row['reason']])
                ->all(),
        ];
    }

    /**
     * Commits a bulk bill adjustment — "increase every customer in Zone
     * THR01 by 500 FCFA" / "set every VIP customer to 5,000 FCFA" — the
     * annual price-adjustment tool office staff use instead of editing each
     * customer one at a time through update(). Uses the exact same
     * planBulkBillUpdate() (and computeAdjustedBill() bcmath) as
     * previewBulkBillUpdate() above; nothing about the computation is
     * duplicated between the two.
     *
     * One bad row (a computed new bill that would be non-positive or over
     * the max) is skipped with a reason rather than failing the whole
     * batch, matching the {succeeded|updated, failed|skipped} partial-
     * success shape used throughout this session's bulk features
     * (PaymentService::createBulk(), CustomerStatusService's *Many()
     * methods). Each write goes through the repository's update() (via
     * Customer::class's Auditable trait), so every bill change is
     * automatically captured in audit_logs with its old/new value — no
     * separate audit trail needed here.
     *
     * @param  string[]|null  $customerUuids
     * @param  array<string, mixed>  $filters
     * @return array{updated: string[], skipped: array<string, string>}
     */
    public function bulkUpdateBill(?array $customerUuids, array $filters, string $mode, string $value): array
    {
        $plan = $this->planBulkBillUpdate($customerUuids, $filters, $mode, $value);

        $updated = [];
        $skipped = [];

        foreach ($plan as $row) {
            $customer = $row['customer'];

            if ($row['new_bill'] === null) {
                $skipped[$customer->uuid] = $row['reason'];

                continue;
            }

            // services.md section 8: the bulk-bill tool only ever adjusts a
            // SINGLE-service customer — it rewrites that one subscription's
            // price and lets recomputeBill() re-derive `customers.bill`.
            // Multi-service customers are skipped above (planBulkBillUpdate).
            $this->subscriptions->setSingleServicePrice($customer, $row['new_bill']);
            $this->forgetShowCache($customer->uuid);

            $updated[] = $customer->uuid;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Resolves the target customer set, then computes each one's adjusted
     * bill (or a skip reason) WITHOUT persisting anything — the single
     * shared computation both previewBulkBillUpdate() and bulkUpdateBill()
     * call, so preview and commit can never disagree.
     *
     * @param  string[]|null  $customerUuids
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{customer: Customer, new_bill: ?string, reason: ?string}>
     */
    private function planBulkBillUpdate(?array $customerUuids, array $filters, string $mode, string $value): Collection
    {
        $customers = $this->resolveCustomersForBulkBillUpdate($customerUuids, $filters);

        // services.md section 8: a customer holding 2+ services can't have a
        // single bulk figure applied — their bill is a sum across services,
        // each priced independently. Skipped with a reason (never silently
        // touched), same partial-success shape as an out-of-range bill.
        $customers->loadCount('subscriptions');

        return $customers->map(function (Customer $customer) use ($mode, $value): array {
            if ((int) ($customer->subscriptions_count ?? 0) >= 2) {
                return [
                    'customer' => $customer,
                    'new_bill' => null,
                    'reason' => "{$customer->name} has multiple services — adjust each one in Settings -> Services or on the customer's edit form.",
                ];
            }

            $newBill = $this->computeAdjustedBill((string) $customer->bill, $mode, $value);

            try {
                $this->assertValidBill($newBill);

                return ['customer' => $customer, 'new_bill' => $newBill, 'reason' => null];
            } catch (ValidationException $e) {
                $reason = collect($e->errors())->flatten()->implode(' ');

                return ['customer' => $customer, 'new_bill' => null, 'reason' => "{$customer->name} {$reason}"];
            }
        });
    }

    /**
     * Explicit customer_uuids selection always wins when given and
     * non-empty; otherwise falls back to the filter descriptor
     * (zone_uuid/level/status/search). At least one of the two must
     * actually narrow the selection — an empty/all-null $filters with no
     * $customerUuids would otherwise silently mean "every customer in the
     * tenant", which is never what a blank form submission intends, so
     * that combination is rejected defensively here (on top of
     * BulkUpdateCustomerBillRequest's own withValidator() check) rather
     * than trusted to the caller.
     *
     * @param  string[]|null  $customerUuids
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Customer>
     */
    private function resolveCustomersForBulkBillUpdate(?array $customerUuids, array $filters): Collection
    {
        if ($customerUuids !== null && count($customerUuids) > 0) {
            return $this->customers->findManyByUuids($customerUuids);
        }

        $hasFilter = collect($filters)->filter(fn (mixed $value): bool => $value !== null && $value !== '')->isNotEmpty();

        if (! $hasFilter) {
            throw ValidationException::withMessages([
                'customer_uuids' => ['Select customers explicitly or provide at least one filter (zone, level, status, or search).'],
            ]);
        }

        if (! empty($filters['zone_uuid'])) {
            $filters['zone_id'] = $this->resolveZoneId($filters['zone_uuid']);
        }

        return $this->customers->allMatching($filters);
    }

    /**
     * The one bcmath computation shared by preview and commit. All money
     * math uses bcadd/bcmul/bcdiv string arithmetic, never native float
     * operators, matching ManuscriptCalculator's established convention
     * since this is money.
     *
     * - set: the new bill IS $value.
     * - increase_fixed: $value FCFA added to the current bill (a negative
     *   $value decreases it).
     * - increase_percent: current bill adjusted by $value percent (a
     *   negative $value decreases it). The percentage multiply/divide is
     *   carried at 6 decimal places internally and only rounded down to the
     *   final 2-decimal-place FCFA amount at the very end, so a small
     *   percentage on a small bill doesn't get truncated away prematurely.
     */
    private function computeAdjustedBill(string $currentBill, string $mode, string $value): string
    {
        $currentBill = bcadd($currentBill, '0.00', 2);
        $value = bcadd($value, '0.00', 2);

        return match ($mode) {
            'set' => $value,
            'increase_fixed' => bcadd($currentBill, $value, 2),
            'increase_percent' => bcadd($currentBill, bcdiv(bcmul($currentBill, $value, 6), '100', 6), 2),
            default => throw ValidationException::withMessages(['mode' => ['Unsupported bill adjustment mode.']]),
        };
    }

    /**
     * The computed new bill must satisfy the exact same constraints as
     * StoreCustomerRequest/UpdateCustomerRequest's `bill` rule (positive,
     * within the DECIMAL(12,2) column's max) — checked via bccomp() string
     * comparison, never a float comparison.
     */
    private function assertValidBill(string $bill): void
    {
        if (bccomp($bill, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'bill' => ["would result in a non-positive bill ({$bill} FCFA)."],
            ]);
        }

        if (bccomp($bill, '999999999.99', 2) > 0) {
            throw ValidationException::withMessages([
                'bill' => ["would exceed the maximum allowed bill ({$bill} FCFA)."],
            ]);
        }
    }
}
