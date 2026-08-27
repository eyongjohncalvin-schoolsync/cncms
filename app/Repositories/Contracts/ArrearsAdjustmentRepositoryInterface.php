<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ArrearsAdjustment;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ArrearsAdjustmentRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'status', 'customer_uuid'.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findByUuid(string $uuid, array $with = []): ?ArrearsAdjustment;

    /**
     * @param  array<string, mixed>  $attributes  Everything from
     *                                            ArrearsAdjustmentData::toAttributes()
     *                                            plus the service-resolved
     *                                            customer_id/arrears_snapshot/complaint_id.
     */
    public function create(int $requestedBy, array $attributes): ArrearsAdjustment;

    public function update(ArrearsAdjustment $adjustment, array $attributes): ArrearsAdjustment;

    /**
     * Recent adjustments for one customer, newest first — backs the "recent
     * adjustments" card on Customers/Show.tsx.
     *
     * @return Collection<int, ArrearsAdjustment>
     */
    public function recentForCustomer(int $customerId, int $limit = 10): Collection;

    /**
     * Whether $customerId has any 'approved' adjustment with `approved_at`
     * on or after $since — backs the 90-day-repeat second-approval gate
     * (App\Services\ArrearsAdjustmentService::requiresSecondApproval()).
     * $excludingId lets the gate check exclude the very adjustment being
     * evaluated.
     */
    public function hasApprovedSince(int $customerId, \DateTimeInterface $since, ?int $excludingId = null): bool;

    /**
     * @return array{pending_approval: int, applied_this_month: int, total_written_off: string}
     */
    public function dashboardCounts(): array;
}
