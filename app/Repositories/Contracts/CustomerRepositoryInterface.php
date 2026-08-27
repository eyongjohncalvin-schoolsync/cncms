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
     *                                         'has_phone'.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findByUuid(string $uuid, array $with = []): ?Customer;

    public function create(int $zoneId, CustomerData $data): Customer;

    public function update(Customer $customer, CustomerData $data, ?int $zoneId = null): Customer;

    public function delete(Customer $customer): bool;

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
