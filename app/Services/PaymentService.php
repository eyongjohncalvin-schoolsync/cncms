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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly CustomerRepositoryInterface $customers,
        private readonly ZoneRepositoryInterface $zones,
        private readonly TenantContext $context,
        private readonly PaymentReceiptService $receipts,
        private readonly FieldReceiptService $fieldReceipts,
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
        return $this->createForCustomer($this->resolveCustomer($data->customerUuid), $data);
    }

    /**
     * create() for a caller that has already resolved the customer (bulk
     * entry, offline sync, reconnection fines) — skips re-looking it up by
     * uuid. $customer must be the customer $data->customerUuid names.
     */
    public function createForCustomer(Customer $customer, PaymentData $data): Payment
    {
        // RBAC v2: mirrors PaymentPolicy::verify() exactly — the office gate
        // is now the `payments.verify` catalog permission; the agent
        // zone-scoped branch stays an additive OR (agent is NOT seeded
        // `payments.verify`).
        $canAutoVerify = $this->context->can('payments.verify')
            || ($this->context->role === 'agent'
                && $this->context->zoneId !== null
                && $customer->zone_id === $this->context->zoneId);

        $this->assertValidMethod($data);
        $this->assertValidPurpose($data);

        $attributes = $data->toAttributes();
        $attributes['verification_status'] = $canAutoVerify ? 'verified' : 'pending';
        $attributes['recorded_by'] = auth()->id();
        $attributes['expiration_date'] = $this->computeExpirationDate($data->frequency, $data->months);

        // Installation / technician-fee money is a one-off amount, never
        // prepaid months (docs/plans/installation-and-promotions.md).
        if (($data->purpose ?? Payment::PURPOSE_SUBSCRIPTION) !== Payment::PURPOSE_SUBSCRIPTION) {
            $attributes['frequency'] = 'monthly';
            unset($attributes['months'], $attributes['clear_arrears_first']);
        }

        // Draw-down credit (references/prepayment-drawdown.md): a months/yearly
        // payment locks the customer's current bill as its prepaid_rate, so a
        // later rate change never shortens the coverage it bought (PD-3).
        if (in_array($attributes['frequency'] ?? null, ['months', 'yearly'], true)) {
            $attributes['prepaid_rate'] = (string) $customer->bill;
            $attributes['clear_arrears_first'] = $this->clearsArrearsFirst($data);
        }

        if ($data->receiptNumber !== null) {
            return $this->createWithFieldReceipt($customer, $data, $attributes);
        }

        $payment = $this->payments->create($customer->id, $attributes);
        $payment->setRelation('customer', $customer);

        // A verified instalment may settle its installation charge.
        if ($payment->verification_status === 'verified') {
            $payment->customerCharge?->refreshStatus();
        }

        // A payment verified on the spot (office roles, an agent in their own
        // zone) gets its receipt now — the same thing approving a pending
        // payment does in PaymentVerificationService::verify().
        if ($payment->verification_status === 'verified') {
            $this->receipts->issueFor($payment, auth()->user());
        }

        return $payment;
    }

    /**
     * Only server-side callers ever set a purpose. An installation payment
     * must name the open installation charge it pays, and only an
     * installation payment may name one.
     */
    private function assertValidPurpose(PaymentData $data): void
    {
        $purpose = $data->purpose ?? Payment::PURPOSE_SUBSCRIPTION;

        if (! in_array($purpose, Payment::PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => "Unknown payment purpose [{$purpose}]."]);
        }

        if (($purpose === Payment::PURPOSE_INSTALLATION) !== ($data->customerChargeId !== null)) {
            throw ValidationException::withMessages(['purpose' => 'An installation payment must be recorded against an installation charge.']);
        }
    }

    /**
     * A payment the collector already gave a system-issued receipt for in
     * the field (docs/plans/cash-controls.md, wave 2). The receipt is checked
     * first (FieldReceiptService — number held by this collector and unused,
     * code genuine) and the payment, its receipt and the used number are
     * written in ONE transaction: a refused receipt stores nothing, and a
     * number can never end up used without its payment or vice versa.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createWithFieldReceipt(Customer $customer, PaymentData $data, array $attributes): Payment
    {
        $actor = auth()->user();

        $payment = DB::transaction(function () use ($customer, $data, $attributes, $actor): Payment {
            $claim = $this->fieldReceipts->claim(
                $actor,
                $customer,
                (string) $data->amount,
                (string) $data->receiptNumber,
                $data->securityCode,
                $data->receiptIssuedAt,
            );

            $payment = $this->payments->create($customer->id, $attributes);
            $payment->setRelation('customer', $customer);

            $this->receipts->issueField($payment, $claim['leaf'], $claim['issued_at'], $claim['device'], $data->securityCode, $actor);

            return $payment;
        });

        $this->receipts->autoSend($payment->receipt, $payment);

        return $payment;
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

        // One query for the whole selection rather than a lookup per uuid.
        // Zone/branch and latest manuscript are what each payment's receipt
        // reads when it's issued on the spot.
        $customersByUuid = $this->customers->findManyByUuids($customerUuids, ['zone.branch', 'latestManuscript'])->keyBy('uuid');

        foreach ($customerUuids as $customerUuid) {
            $customer = $customersByUuid->get($customerUuid);

            if (! $customer) {
                $failed[$customerUuid] = 'Customer not found.';

                continue;
            }

            if (in_array($customer->status, ['disconnected', 'suspended'], true)) {
                $failed[$customerUuid] = "{$customer->name} is currently {$customer->status} and cannot be paid until reconnected.";

                continue;
            }

            try {
                $created[] = $this->createForCustomer($customer, new PaymentData(
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
        $this->assertValidMethod($data, $payment);
        $this->assertNotHandedOver($payment, $data);

        if (! $payment->isSubscription() && $data->frequency !== null && $data->frequency !== 'monthly') {
            throw ValidationException::withMessages(['frequency' => 'Only a subscription payment can buy prepaid months.']);
        }

        $attributes = $data->toAttributes();

        if ($data->clearArrearsFirst !== null) {
            $attributes['clear_arrears_first'] = $this->clearsArrearsFirst($data);
        }

        if ($data->frequency !== null || $data->months !== null) {
            $attributes['expiration_date'] = $this->computeExpirationDate(
                $data->frequency ?? $payment->frequency,
                $data->months ?? $payment->months,
            );
        }

        $payment = $this->payments->update($payment, $attributes);

        $payment->customerCharge?->refreshStatus();

        $this->forgetShowCache($payment->uuid);

        return $payment->load('customer');
    }

    /**
     * The Form Requests already apply Payment::methodRules(); this repeats
     * the check for callers that build PaymentData without one — the
     * offline sync push, whose items are not validated by a request. A
     * mobile-money payment without its transaction ID can never be ticked
     * off the wallet statement, so it is refused rather than stored.
     */
    private function assertValidMethod(PaymentData $data, ?Payment $existing = null): void
    {
        $method = $data->method ?? $existing?->method ?? 'cash';
        $reference = $data->methodReference ?? $existing?->method_reference;

        if (! in_array($method, Payment::METHODS, true)) {
            throw ValidationException::withMessages(['method' => "Unknown payment method [{$method}]."]);
        }

        if (in_array($method, Payment::METHODS_REQUIRING_REFERENCE, true) && ($reference === null || $reference === '')) {
            throw ValidationException::withMessages([
                'method_reference' => 'Enter the '.Payment::METHOD_LABELS[$method].' transaction ID.',
            ]);
        }
    }

    /**
     * Cash controls: once a payment's money has gone through a daily
     * handover (a remittance line), its amount and method are part of the
     * cash book — changing them would silently unbalance a counted handover.
     * Other corrections (frequency, months) stay allowed.
     */
    private function assertNotHandedOver(Payment $payment, PaymentData $data): void
    {
        $changesMoney = ($data->amount !== null && bccomp($data->amount, (string) $payment->amount, 2) !== 0)
            || ($data->method !== null && $data->method !== $payment->method);

        if ($changesMoney && $payment->remittanceLine()->exists()) {
            throw ValidationException::withMessages([
                'amount' => 'This payment\'s money has already been handed over in a daily remittance — its amount and method can no longer change.',
            ]);
        }
    }

    public function delete(Payment $payment): void
    {
        if ($payment->remittanceLine()->exists()) {
            throw ValidationException::withMessages([
                'payment' => 'This payment\'s money has already been handed over in a daily remittance and cannot be deleted.',
            ]);
        }

        $charge = $payment->customerCharge;

        $this->payments->delete($payment);

        $charge?->refreshStatus();

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
     * A prepayment pays off what the customer owes first; only the rest buys
     * prepaid months (owner's rule, 2026-10: "a prepaid customer can't have
     * arrears"). Leaving the arrears due is an explicit override reserved
     * for someone who may approve arrears decisions (`arrears.approve`) —
     * anyone else, including an agent's phone or an older mobile build that
     * still sends `false`, always clears arrears first.
     */
    private function clearsArrearsFirst(PaymentData $data): bool
    {
        if ($data->clearArrearsFirst === false && $this->context->can('arrears.approve')) {
            return false;
        }

        return true;
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
