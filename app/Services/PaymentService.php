<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\PaymentData;
use App\Models\Customer;
use App\Models\Payment;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly CustomerRepositoryInterface $customers,
        private readonly ZoneRepositoryInterface $zones,
        private readonly TenantContext $context,
    ) {}

    /**
     * Payments are written frequently (agents recording, admins verifying),
     * so precise invalidation of every filter/page combination isn't
     * practical. A short TTL is the pragmatic tradeoff: results can be up to
     * ~25s stale, which is acceptable for a list view.
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (! empty($filters['customer_uuid'])) {
            $filters['customer_id'] = $this->resolveCustomerId($filters['customer_uuid']);
        }

        if (! empty($filters['zone_uuid'])) {
            $filters['zone_id'] = $this->resolveZoneIdFromUuid($filters['zone_uuid']);
        }

        // Branch fence baked into the key — see the identical note on
        // App\Services\CustomerService::list(): PaymentRepository's queries
        // are now branch-scoped, so the cache must not be shared across
        // callers with different effective branches.
        $cacheKey = 'payments:list:'.($this->context->branchId ?? 'all').':'
            .md5(json_encode([$filters, $perPage, request()->query('page', 1)]));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(25),
            fn (): LengthAwarePaginator => $this->payments->paginate($filters, $perPage),
        );
    }

    public function findOrFail(string $uuid): Payment
    {
        return Cache::remember(
            "payments:show:{$uuid}:".($this->context->branchId ?? 'all'),
            now()->addSeconds(30),
            function () use ($uuid): Payment {
                $payment = $this->payments->findByUuid($uuid, ['customer', 'verification.verifier']);

                if (! $payment) {
                    throw new ModelNotFoundException("Payment [{$uuid}] not found.");
                }

                return $payment;
            },
        );
    }

    /**
     * Backs the "pending verifications" dashboard/tab-badge figure. Changes
     * often (new payments, verify/reject actions) so a short TTL is used
     * instead of trying to invalidate it from every payment-mutating call
     * site.
     */
    public function pendingVerificationCount(): int
    {
        $branchId = TenantContext::currentBranchId();

        return Cache::remember(
            'payments:pending_count:'.($branchId ?? 'all'),
            now()->addSeconds(15),
            fn (): int => Payment::query()
                ->where('verification_status', 'pending')
                ->when($branchId !== null, fn ($query) => $query->whereHas(
                    'customer.zone',
                    fn ($inner) => $inner->where('branch_id', $branchId),
                ))
                ->count(),
        );
    }

    /**
     * Creates a payment. verification_status is never taken from the
     * client — it is auto-verified if and only if the creating actor could
     * ALSO verify this exact payment via PaymentPolicy::verify() (2026-08
     * revision): unconditionally true for super/admin/manager, or true for
     * an agent when the customer is in that agent's own zone (mirroring
     * verify()'s zone fence exactly), false otherwise (a plain agent
     * outside their zone, or a flagged worker, which never has verify
     * power at all). Channel (offline-synced vs. recorded live/on-site) is
     * deliberately NOT a factor — the owner's explicit call: trust is a
     * property of the ROLE/scope doing the recording, not of how or where
     * it was recorded. `recorded_offline`/`recorded_by_device` are still
     * stored (App\Services\SyncService's mobile-sync path sets them, the
     * web UI never does) purely for the Index/Show pages' "Offline"/
     * "Office" display badge and audit trail — they no longer gate
     * verification_status. expiration_date is likewise always computed
     * server-side from frequency/months, never accepted as raw client
     * input.
     */
    public function create(PaymentData $data): Payment
    {
        $customer = $this->resolveCustomer($data->customerUuid);

        $canAutoVerify = $this->context->isAnyOf('super', 'admin', 'manager')
            || ($this->context->role === 'agent'
                && $this->context->zoneId !== null
                && $customer->zone_id === $this->context->zoneId);

        $attributes = $data->toAttributes();
        $attributes['verification_status'] = $canAutoVerify ? 'verified' : 'pending';
        $attributes['expiration_date'] = $this->computeExpirationDate($data->frequency, $data->months);

        // Draw-down credit (references/prepayment-drawdown.md): a months/yearly
        // payment locks the customer's current bill as its prepaid_rate, so a
        // later rate change never shortens the coverage it bought (PD-3).
        if (in_array($data->frequency, ['months', 'yearly'], true)) {
            $attributes['prepaid_rate'] = (string) $customer->bill;
        }

        $payment = $this->payments->create($customer->id, $attributes);

        return $payment->load('customer');
    }

    /**
     * Records one payment per customer, each at that customer's own `bill`
     * — the "10 customers, all paying exactly their 2,500 FCFA monthly
     * bill" bulk-entry scenario. Delegates to create() per row so
     * verification_status/expiration_date stay governed by that single
     * source of truth (office roles auto-verify, agents enter pending —
     * bulk entry doesn't change who gets auto-approved, it just saves
     * re-typing the same amount N times).
     *
     * One bad row (customer deleted mid-selection, etc.) is skipped rather
     * than failing the whole batch — a partial success is more useful here
     * than forcing the caller to re-pick from scratch. A `disconnected` or
     * `suspended` customer selected alongside eligible ones is skipped the
     * same way (`passive` is left payable, deliberately not blocked) —
     * this check lives ONLY in this method's loop, not in create() itself,
     * because create() is also called directly by
     * App\Services\CustomerStatusService::reconnectOne() to record the
     * reconnection-fine payment while the customer is still `disconnected`;
     * that call must keep working, so the guard must not reach it.
     *
     * @param  string[]  $customerUuids
     * @return array{created: Payment[], failed: array<string, string>}
     */
    public function createBulk(array $customerUuids, string $frequency, ?int $months): array
    {
        $created = [];
        $failed = [];

        foreach ($customerUuids as $customerUuid) {
            $customer = $this->customers->findByUuid($customerUuid);

            if (! $customer) {
                $failed[$customerUuid] = 'Customer not found.';

                continue;
            }

            if (in_array($customer->status, ['disconnected', 'suspended'], true)) {
                $failed[$customerUuid] = "{$customer->name} is currently {$customer->status} and cannot be paid until reconnected.";

                continue;
            }

            try {
                $created[] = $this->create(new PaymentData(
                    customerUuid: $customerUuid,
                    amount: (string) $customer->bill,
                    frequency: $frequency,
                    months: $months,
                ));
            } catch (ValidationException $e) {
                $failed[$customerUuid] = collect($e->errors())->flatten()->implode(' ');
            }
        }

        return ['created' => $created, 'failed' => $failed];
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

        $this->forgetShowCache($payment->uuid);

        return $payment->load('customer');
    }

    public function delete(Payment $payment): void
    {
        $this->payments->delete($payment);

        $this->forgetShowCache($payment->uuid);
    }

    /**
     * See App\Services\CustomerService::forgetShowCache()'s doc comment —
     * same branch-suffixed-key tradeoff.
     */
    private function forgetShowCache(string $uuid): void
    {
        Cache::forget("payments:show:{$uuid}:".($this->context->branchId ?? 'all'));
        Cache::forget("payments:show:{$uuid}:all");
    }

    /**
     * Draw-down cutover (references/prepayment-drawdown.md §10 step 2): new
     * `months`/`yearly` payments no longer carry an `expiration_date` — their
     * value flows through the draw-down branch as prepaid months against the
     * ledger, not a calendar freeze. Pre-cutover rows keep their stored date
     * and ride the legacy freeze branch until it lapses. The method is kept
     * (still called from update()) but now always returns null; the derived
     * "covered through" date is computed for display from
     * `manuscripts.prepaid_months_remaining` instead.
     */
    private function computeExpirationDate(?string $frequency, ?int $months): ?string
    {
        unset($frequency, $months);

        return null;
    }

    private function resolveCustomerId(?string $customerUuid): int
    {
        return $this->resolveCustomer($customerUuid)->id;
    }

    /**
     * Full-model variant of resolveCustomerId() above — needed by create()
     * to read the customer's zone_id (for the agent-zone-fence auto-verify
     * check) without a second query.
     */
    private function resolveCustomer(?string $customerUuid): Customer
    {
        if (! $customerUuid) {
            throw ValidationException::withMessages(['customer_uuid' => ['The customer_uuid field is required.']]);
        }

        $customer = $this->customers->findByUuid($customerUuid);

        if (! $customer) {
            throw ValidationException::withMessages(['customer_uuid' => ['The selected customer does not exist.']]);
        }

        return $customer;
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
