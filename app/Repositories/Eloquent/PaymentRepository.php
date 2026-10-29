<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Payment;
use App\Repositories\Concerns\ScopesByBranch;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PaymentRepository implements PaymentRepositoryInterface
{
    use ScopesByBranch;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scopeToBranchDirect(Payment::query(), $this->currentBranchId())
            // An archived customer is out of every list (owner, 2026-10-29) — their payments stay on their own page.
            ->whereHas('customer')
            // remittanceLine: whether the cash was handed in (the Reverse dialog's choices).
            ->with(['customer', 'verification.verifier', 'remittanceLine', 'customerCharge', 'recordedBy:id,name'])
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when(
                $filters['zone_id'] ?? null,
                fn ($query, $zoneId) => $query->whereHas('customer', fn ($inner) => $inner->where('zone_id', $zoneId))
            )
            ->when(
                $filters['verification_status'] ?? null,
                fn ($query, $status) => $query->where('verification_status', $status),
                // The default list is money: rejected payments only show under Rejected.
                fn ($query) => ($filters['duplicates'] ?? false) ? $query : $query->where('verification_status', '!=', 'rejected'),
            )
            // Every customer with more than one payment in a month, until the office clears it.
            ->when($filters['duplicates'] ?? false, fn ($query) => $query->possibleDuplicate()
                // A customer's payments of one month side by side, newest month first.
                ->orderByRaw("date_trunc('month', coalesce(payments.collected_at, payments.created_at)) desc")
                ->orderBy('customer_id')
                ->orderByRaw('coalesce(payments.collected_at, payments.created_at)'))
            // Bill money vs one-off fees (installation, relocation, re-installation).
            ->when(($filters['purpose'] ?? null) === 'bill', fn ($query) => $query->subscription())
            ->when(($filters['purpose'] ?? null) === 'fees', fn ($query) => $query->where('purpose', Payment::PURPOSE_INSTALLATION))
            ->when($filters['frequency'] ?? null, fn ($query, $frequency) => $query->where('frequency', $frequency))
            ->when($filters['recorded_offline'] ?? null, fn ($query, bool $recordedOffline) => $query->where('recorded_offline', $recordedOffline))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            // Same name-or-phone convention as CustomerRepository::paginate()
            // / ::allMatching()'s identical 'search' clause — a payment has
            // no free-text field of its own worth searching, so this matches
            // against its customer relation instead.
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->whereHas('customer', function ($inner) use ($search) {
                    $inner->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid, array $with = []): ?Payment
    {
        return $this->scopeToBranchDirect(Payment::query(), $this->currentBranchId())
            ->with($with)
            ->where('uuid', $uuid)
            ->first();
    }

    public function findManyByUuids(array $uuids, array $with = []): Collection
    {
        if ($uuids === []) {
            return new Collection;
        }

        return $this->scopeToBranchDirect(Payment::query(), $this->currentBranchId())
            ->with($with)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');
    }

    public function create(int $customerId, array $attributes): Payment
    {
        return Payment::query()->create(['customer_id' => $customerId, ...$attributes]);
    }

    public function update(Payment $payment, array $attributes): Payment
    {
        $payment->update($attributes);

        return $payment;
    }

    public function delete(Payment $payment): bool
    {
        return (bool) $payment->delete();
    }
}
