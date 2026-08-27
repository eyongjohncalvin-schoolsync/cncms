<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\ArrearsAdjustment;
use App\Repositories\Contracts\ArrearsAdjustmentRepositoryInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ArrearsAdjustmentRepository implements ArrearsAdjustmentRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scoped($filters)
            ->with(['customer.zone', 'requestedBy', 'approvedBy', 'secondApprovedBy'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid, array $with = []): ?ArrearsAdjustment
    {
        return ArrearsAdjustment::query()->with($with)->where('uuid', $uuid)->first();
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
        $pendingApproval = ArrearsAdjustment::query()
            ->whereIn('status', ['pending', 'pending_second_approval'])
            ->count();

        $appliedThisMonth = ArrearsAdjustment::query()
            ->where('status', 'approved')
            ->where('approved_at', '>=', Carbon::now()->startOfMonth())
            ->count();

        $totalWrittenOff = ArrearsAdjustment::query()
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
        return ArrearsAdjustment::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(
                $filters['customer_uuid'] ?? null,
                fn (Builder $query, string $uuid) => $query->whereHas('customer', fn (Builder $inner) => $inner->where('uuid', $uuid))
            );
    }
}
