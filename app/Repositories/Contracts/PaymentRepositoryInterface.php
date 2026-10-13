<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface PaymentRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'customer_id',
     *                                         'zone_id', 'verification_status',
     *                                         'frequency', 'recorded_offline',
     *                                         'from', 'to', 'search' (matches
     *                                         the payment's customer's name
     *                                         or phone — see
     *                                         CustomerRepository::paginate()'s
     *                                         identical 'search' clause).
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findByUuid(string $uuid, array $with = []): ?Payment;

    /**
     * The batched form of findByUuid() for bulk actions — same branch
     * scoping, one query, keyed by uuid.
     *
     * @param  string[]  $uuids
     * @param  array<int, string>  $with
     * @return Collection<string, Payment>
     */
    public function findManyByUuids(array $uuids, array $with = []): Collection;

    public function create(int $customerId, array $attributes): Payment;

    public function update(Payment $payment, array $attributes): Payment;

    public function delete(Payment $payment): bool;
}
