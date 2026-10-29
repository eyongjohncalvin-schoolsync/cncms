<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\ArrearsAdjustment;
use App\Repositories\Concerns\ScopesByBranch;
use App\Repositories\Contracts\ArrearsAdjustmentRepositoryInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ArrearsAdjustmentRepository implements ArrearsAdjustmentRepositoryInterface
{
    use ScopesByBranch;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scoped($filters)
            ->with(['customer.zone', 'requestedBy', 'approvedBy', 'secondApprovedBy'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid, array $with = []): ?ArrearsAdjustment
    {
        return $this->fenced()->with($with)->where('uuid', $uuid)->first();
    }

    public function findManyByUuids(array $uuids): Collection
    {
        return $this->fenced()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
    }

    /**
     * `requested_by` is deliberately absent from ArrearsAdjustment's
     * #[Fillable] list (see the model's class doc) — mass-assigning it
     * would be silently dropped, so it's set via direct property assignment,
     * the same shape as ComplaintRepository::create()'s submitted_by write.
     */
    public function create(int $requestedBy, array $attributes): ArrearsAdjustment
    {
        $adjustment = new ArrearsAdjustment($attributes);
        $adjustment->requested_by = $requestedBy;
        $adjustment->save();

        return $adjustment;
    }

    public function update(ArrearsAdjustment $adjustment, array $attributes): ArrearsAdjustment
    {
        $adjustment->update($attributes);

        return $adjustment;
    }

    public function recentForCustomer(int $customerId, int $limit = 10): Collection
    {
        return ArrearsAdjustment::query()
            ->where('customer_id', $customerId)
            ->with(['requestedBy', 'approvedBy', 'secondApprovedBy'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function hasApprovedSince(int $customerId, \DateTimeInterface $since, ?int $excludingId = null): bool
    {
        return ArrearsAdjustment::query()
            ->where('customer_id', $customerId)
            ->where('status', 'approved')
            ->where('approved_at', '>=', $since)
            ->when($excludingId !== null, fn (Builder $query) => $query->where('id', '!=', $excludingId))
            ->exists();
    }

    public function dashboardCounts(): array
    {
        $pendingApproval = $this->fenced()
            ->whereIn('status', ['pending', 'pending_second_approval'])
            ->count();

        $appliedThisMonth = $this->fenced()
            ->where('status', 'approved')
            ->where('approved_at', '>=', Carbon::now()->startOfMonth())
            ->count();

        $totalWrittenOff = $this->fenced()
            ->where('status', 'approved')
            ->where('direction', 'decrease')
            ->sum('amount');

        return [
            'pending_approval' => $pendingApproval,
            'applied_this_month' => $appliedThisMonth,
            'total_written_off' => (string) $totalWrittenOff,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function scoped(array $filters): Builder
    {
        return $this->fenced()
            // An archived customer is out of every list (owner, 2026-10-29).
            ->whereHas('customer')
            // 'awaiting_approval' = both pending stages — the same set the
            // dashboard's pending_approval count uses (dashboardCounts()).
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $status === 'awaiting_approval'
                ? $query->whereIn('status', ['pending', 'pending_second_approval'])
                : $query->where('status', $status))
            ->when(
                $filters['customer_uuid'] ?? null,
                fn (Builder $query, string $uuid) => $query->whereHas('customer', fn (Builder $inner) => $inner->where('uuid', $uuid))
            );
    }

    /**
     * Branches v2: adjustments are customer history (no global branch scope),
     * so lists, counts and lookups by uuid are fenced here; lookups for one
     * customer stay whole.
     */
    private function fenced(): Builder
    {
        return $this->scopeToBranchDirect(ArrearsAdjustment::query(), $this->currentBranchId());
    }
}
