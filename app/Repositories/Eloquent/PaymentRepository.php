<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Payment::query()
            ->with('customer')
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when(
                $filters['zone_id'] ?? null,
                fn ($query, $zoneId) => $query->whereHas('customer', fn ($inner) => $inner->where('zone_id', $zoneId))
            )
            ->when(
                $filters['verification_status'] ?? null,
                fn ($query, $status) => $query->where('verification_status', $status)
            )
            ->when($filters['frequency'] ?? null, fn ($query, $frequency) => $query->where('frequency', $frequency))
            ->when($filters['recorded_offline'] ?? null, fn ($query, bool $recordedOffline) => $query->where('recorded_offline', $recordedOffline))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid, array $with = []): ?Payment
    {
        return Payment::query()->with($with)->where('uuid', $uuid)->first();
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
