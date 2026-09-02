<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\CustomerData;
use App\Models\Customer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface CustomerRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'zone_id',
     *                                         'status', 'level', 'search',
     *                                         'has_phone', 'archived' (bool —
     *                                         true returns ONLY archived
     *                                         customers; omitted/false
     *                                         returns only active ones, the
     *                                         SoftDeletes default).
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * @param  bool  $withTrashed  Include an archived (soft-deleted)
     *                             customer in the lookup — needed by the
     *                             show page and the restore action, which
     *                             must resolve a customer that ordinary
     *                             queries now hide.
     */
    public function findByUuid(string $uuid, array $with = [], bool $withTrashed = false): ?Customer;

    public function create(int $zoneId, CustomerData $data): Customer;

    public function update(Customer $customer, CustomerData $data, ?int $zoneId = null): Customer;

    /**
     * Hard delete (forceDelete) — only ever called for a customer with zero
     * billing history. A customer with history is archived, not deleted.
     */
    public function delete(Customer $customer): bool;

    /**
     * Archive (soft delete) a customer, stamping who did it and why.
     * `archived_by`/`archived_reason` are written with an explicit save()
     * before the soft delete so the Auditable trail carries one "Archived
     * customer" row — see App\Services\CustomerService::archive().
     */
    public function archive(Customer $customer, int $actorId, string $reason): void;

    /** Restore an archived customer, clearing the archive stamp. */
    public function restore(Customer $customer): void;

    /**
     * Raw-attribute update used by the dedicated status actions
     * (App\Services\CustomerStatusService) instead of update()'s
     * CustomerData/toAttributes() path — those actions always want to set
     * `status_reason`/`status_note` explicitly (including clearing a stale
     * note back to null), which toAttributes()'s null-filtering (built for
     * the generic partial-edit form) would silently skip. Mirrors
     * PaymentVerificationRepositoryInterface::update()'s raw-array shape.
     */
    public function updateStatus(Customer $customer, array $attributes): Customer;

    /**
     * Active customers eager-loaded with `zone` and `latestManuscript`,
     * optionally scoped to a single zone — the read path behind
     * App\Services\CustomerEligibilityService's arrears-based
     * disconnection-eligibility scan. Only `active` customers are
     * returned: `disconnected` customers are already off the board,
     * `suspended`/`passive` customers are handled through the ordinary
     * manual disconnect flow rather than the automatic arrears monitor.
     *
     * @return Collection<int, Customer>
     */
    public function activeWithLatestManuscript(?int $zoneId = null): Collection;

    /**
     * Customers matching an explicit list of uuids, scoped to the caller's
     * branch fence exactly like findByUuid() — backs the bulk bill-update
     * tool's "explicit selection" path (App\Services\CustomerService::
     * bulkUpdateBill()/previewBulkBillUpdate()). Any uuid in $uuids that
     * doesn't resolve (deleted mid-selection, wrong branch, typo) is simply
     * absent from the returned Collection rather than raising an error —
     * the caller treats "not found" the same way applyToMany()-style bulk
     * actions elsewhere in the app do.
     *
     * @param  string[]  $uuids
     * @return Collection<int, Customer>
     */
    public function findManyByUuids(array $uuids): Collection;

    /**
     * Every customer matching $filters (same supported keys as paginate():
     * 'zone_id', 'status', 'level', 'search'), with NO pagination — backs
     * the bulk bill-update tool's "select by filter, not by uuid list"
     * path, so a large "every customer in zone X" batch doesn't require the
     * frontend to serialize hundreds of uuids into the request.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Customer>
     */
    public function allMatching(array $filters): Collection;
}
