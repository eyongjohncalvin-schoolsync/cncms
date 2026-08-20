<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\PaymentData;
use App\Models\Payment;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly CustomerRepositoryInterface $customers,
        private readonly ZoneRepositoryInterface $zones,
        private readonly TenantContext $context,
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (! empty($filters['customer_uuid'])) {
            $filters['customer_id'] = $this->resolveCustomerId($filters['customer_uuid']);
        }

        if (! empty($filters['zone_uuid'])) {
            $filters['zone_id'] = $this->resolveZoneIdFromUuid($filters['zone_uuid']);
        }

        return $this->payments->paginate($filters, $perPage);
    }

    public function findOrFail(string $uuid): Payment
    {
        $payment = $this->payments->findByUuid($uuid, ['customer']);

        if (! $payment) {
            throw new ModelNotFoundException("Payment [{$uuid}] not found.");
        }

        return $payment;
    }

    /**
     * Creates a payment. verification_status is never taken from the
     * client — per api-spec.md section 3.2, admin/manager/super-recorded
     * payments are auto-approved, everything else (agents) enters pending
     * for review. expiration_date is likewise always computed server-side
     * from frequency/months, never accepted as raw client input.
     */
    public function create(PaymentData $data): Payment
    {
        $customerId = $this->resolveCustomerId($data->customerUuid);

        $attributes = $data->toAttributes();
        $attributes['verification_status'] = $this->context->isAnyOf('super', 'admin', 'manager')
            ? 'verified'
            : 'pending';
        $attributes['expiration_date'] = $this->computeExpirationDate($data->frequency, $data->months);

        $payment = $this->payments->create($customerId, $attributes);

        return $payment->load('customer');
    }

    public function update(Payment $payment, PaymentData $data): Payment
    {
        $attributes = $data->toAttributes();

        if ($data->frequency !== null || $data->months !== null) {
            $attributes['expiration_date'] = $this->computeExpirationDate(
                $data->frequency ?? $payment->frequency,
                $data->months ?? $payment->months,
            );
        }

        $payment = $this->payments->update($payment, $attributes);

        return $payment->load('customer');
    }

    public function delete(Payment $payment): void
    {
        $this->payments->delete($payment);
    }

    private function computeExpirationDate(?string $frequency, ?int $months): ?string
    {
        return match ($frequency) {
            'yearly' => Carbon::now()->addMonths(12)->toDateString(),
            'months' => $months ? Carbon::now()->addMonths($months)->toDateString() : null,
            default => null,
        };
    }

    private function resolveCustomerId(?string $customerUuid): int
    {
        if (! $customerUuid) {
            throw ValidationException::withMessages(['customer_uuid' => ['The customer_uuid field is required.']]);
        }

        $customer = $this->customers->findByUuid($customerUuid);

        if (! $customer) {
            throw ValidationException::withMessages(['customer_uuid' => ['The selected customer does not exist.']]);
        }

        return $customer->id;
    }

    private function resolveZoneIdFromUuid(string $zoneUuid): ?int
    {
        // Payments are filtered by zone via the customer relation (see
        // PaymentRepository::paginate()'s whereHas('customer', ...)) — the
        // zone_uuid -> zone_id resolution itself is business logic, so it
        // lives here rather than in the (thin) repository.
        return $this->zones->findByUuid($zoneUuid)?->id;
    }
}
