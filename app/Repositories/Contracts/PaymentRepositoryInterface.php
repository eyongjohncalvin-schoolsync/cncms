<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Payment;
use Illuminate\Pagination\LengthAwarePaginator;

interface PaymentRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'customer_id',
     *                                         'zone_id', 'verification_status',
     *                                         'frequency', 'recorded_offline',
     *                                         'from', 'to'.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findByUuid(string $uuid, array $with = []): ?Payment;

    public function create(int $customerId, array $attributes): Payment;

    public function update(Payment $payment, array $attributes): Payment;

    public function delete(Payment $payment): bool;
}
