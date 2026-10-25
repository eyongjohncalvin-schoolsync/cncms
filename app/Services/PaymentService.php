<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\PaymentData;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PrepaymentOffer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Support\Billing\FreeMonthPlan;
use App\Support\BranchLookup;
use App\Support\BranchPrices;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
     * Earlier payments of a batch (primeEarlierPayments()), keyed
     * "customerId|Y-m"; null outside a batch, when each check queries.
     *
     * @var array<string, ?Payment>|null
     */
    private ?array $earlierMemo = null;

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
                ->when($branchId !== null, fn ($query) => $query->where('payments.branch_id', $branchId))
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
        // The payment takes this customer's branch (BelongsToBranch) — no lookup query.
        BranchLookup::remember([$customer]);

        // RBAC v2: mirrors PaymentPolicy::verify() exactly — the office gate
        // is now the `payments.verify` catalog permission; the agent
        // zone-scoped branch stays an additive OR (agent is NOT seeded
        // `payments.verify`).
        $canAutoVerify = $this->context->can('payments.verify')
            || $this->context->isAgentCovering($customer->zone_id);

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

        $attributes = $this->applyPrepaymentOffer($customer, $data, $attributes);
        $attributes = $this->flagPossibleDuplicate($customer, $data, $attributes);

        if ($data->receiptNumber !== null) {
            $payment = $this->createWithFieldReceipt($customer, $data, $attributes);

            // A verified instalment with a field receipt (an offline field
            // installation, phase 3) may settle its charge too.
            if ($payment->verification_status === 'verified') {
                $payment->customerCharge?->refreshStatus();
            }

            $this->rememberEarlier($payment);

            return $payment;
        }

        $payment = $this->payments->create($customer->id, $attributes);
        $payment->setRelation('customer', $customer);
        $this->rememberEarlier($payment);

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
     * A payment made under a prepayment offer ("4 months for 10,000") buys
     * exactly the offer's months for exactly its price in the customer's
     * branch — no amount is ever inflated to make months come out right
     * (App\Services\ManuscriptCalculator honours the offer's months). A
     * customer who still owes pays the debt in the same payment: the amount
     * is the debt plus the offer's price, the debt is cleared first and the
     * offer buys its months with the rest. Not during installation free
     * months.
     *
     * At the counter those rules refuse the payment. From an offline phone
     * the cash was already collected, so it is never refused: a payment that
     * breaks one is recorded under the offer but left pending for the office;
     * an offer that no longer exists falls back to an ordinary prepayment.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyPrepaymentOffer(Customer $customer, PaymentData $data, array $attributes): array
    {
        if ($data->prepaymentOfferUuid === null) {
            return $attributes;
        }

        $offline = (bool) $data->recordedOffline;
        $offer = PrepaymentOffer::query()
            ->where('uuid', $data->prepaymentOfferUuid)
            // At the counter it must be on sale today; a phone may have sold it offline just before it ended.
            ->when(! $offline, fn ($query) => $query->available())
            ->first();

        if ($offer === null) {
            if (! $offline) {
                throw ValidationException::withMessages(['prepayment_offer_uuid' => 'That offer is not available today.']);
            }

            return [...$attributes, 'verification_status' => 'pending'];
        }

        $price = BranchPrices::for($customer->branch_id)->prepaymentOfferPrice($offer);
        // Old debt is cleared first, in the same payment: the amount is the debt plus the offer.
        $debt = $this->unpaidDebt($customer);
        $total = bcadd($debt, $price, 2);
        $problem = match (true) {
            ($data->purpose ?? Payment::PURPOSE_SUBSCRIPTION) !== Payment::PURPOSE_SUBSCRIPTION => 'An offer only pays for the monthly bill.',
            bccomp((string) $data->amount, $total, 2) !== 0 => bccomp($debt, '0', 2) > 0
                ? "{$customer->name} owes ".$this->fcfa($debt).'. Collect '.$this->fcfa($total).": {$this->fcfa($debt)} clears the debt, {$this->fcfa($price)} buys {$offer->name}."
                : "{$offer->name} costs {$this->fcfa($price)} — record exactly that amount.",
            $this->inInstallationFreeMonths($customer) => "{$customer->name} is still in free months from their installation — sell the offer once they end.",
            default => null,
        };

        if ($problem !== null && ! $offline) {
            throw ValidationException::withMessages(['prepayment_offer_uuid' => $problem]);
        }

        return [
            ...$attributes,
            'prepayment_offer_id' => $offer->id,
            'frequency' => 'months',
            'months' => $offer->months,
            'expiration_date' => $this->computeExpirationDate('months', $offer->months),
            // What each of its months is worth (display, and any later credit draw).
            'prepaid_rate' => bcdiv($price, (string) $offer->months, 2),
            'offer_price' => $price,
            // Any old debt in the amount is paid off first; the offer buys its months with the rest.
            'clear_arrears_first' => true,
            'verification_status' => $problem !== null ? 'pending' : ($attributes['verification_status'] ?? 'pending'),
        ];
    }

    private function fcfa(string $amount): string
    {
        return number_format((float) $amount, 0, '.', ',').' FCFA';
    }

    /**
     * Old debt still unpaid: the last bill run's arrears, less any credit and
     * the payments recorded since (not rejected) — so a collector can take
     * the debt and then sell an offer in the same visit.
     */
    private function unpaidDebt(Customer $customer): string
    {
        $customer->loadMissing('latestManuscript');
        $arrears = (string) ($customer->latestManuscript?->total_arrears ?? '0');
        $credit = (string) ($customer->latestManuscript?->credit ?? '0');

        $paidSince = (string) Payment::query()
            ->where('customer_id', $customer->id)
            ->subscription()
            ->whereNull('processed_at')
            ->whereNull('prepayment_offer_id')
            ->where('verification_status', '!=', 'rejected')
            ->sum('amount');

        $debt = bcsub(bcsub($arrears, $credit, 2), $paidSince, 2);

        return bccomp($debt, '0', 2) > 0 ? $debt : '0.00';
    }

    /** Whether the customer's installation free months still run this month (or haven't started billing yet). */
    private function inInstallationFreeMonths(Customer $customer): bool
    {
        $plan = FreeMonthPlan::forCustomer($customer);
        $period = BusinessTimezone::currentPeriod();

        return ! $plan->isEmpty()
            && ($plan->beforeBillingStarts($period) || $plan->allServicesFree($period) || $plan->freeServiceIds($period) !== []);
    }

    /**
     * A second subscription payment for the customer in the same month (by
     * when it was collected) may be a double entry. Without a reason it is
     * refused with a description of the earlier payment, so the collector
     * confirms why it is fine; with one — or `unconfirmed`, which offline
     * sync sends because a phone can't see other collectors' payments — it is
     * recorded but flagged and left pending: never verified on the spot,
     * someone else decides. Customers do pay twice in a month (ahead, old
     * debt), so this asks — it never blocks money that was really collected.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function flagPossibleDuplicate(Customer $customer, PaymentData $data, array $attributes): array
    {
        if (($data->purpose ?? Payment::PURPOSE_SUBSCRIPTION) !== Payment::PURPOSE_SUBSCRIPTION) {
            return $attributes;
        }

        $earlier = $this->earlierPaymentThisMonth($customer, $data->collectedAt);

        if ($earlier === null) {
            return $attributes;
        }

        if ($data->duplicateReason === null) {
            throw ValidationException::withMessages(['duplicate' => $this->describeEarlier($customer, $earlier, (string) $data->amount)]);
        }

        $reason = array_key_exists($data->duplicateReason, Payment::DUPLICATE_REASONS) ? $data->duplicateReason : 'unconfirmed';

        return [
            ...$attributes,
            'duplicate_of_id' => $earlier->id,
            'duplicate_reason' => $reason,
            'duplicate_note' => $data->duplicateNote,
            // Still flagged either way; only its verification differs.
            'verification_status' => $this->reasonIsBorneOut($customer, $data, $attributes, $reason, $earlier)
                ? ($attributes['verification_status'] ?? 'pending')
                : 'pending',
        ];
    }

    /**
     * Whether the payment itself proves the collector's reason, so it may be
     * verified on the spot like any payment (if its recorder may verify):
     *  - "paying ahead" on a real prepayment (months/yearly);
     *  - "old debt" when the customer owes at least that much beyond this
     *    month's bill.
     * Never for the same amount within 3 days of the earlier payment (the
     * classic double entry), nor for refund / other / unconfirmed — those go
     * to the office. Cash controls still catch a faked repeat at handover.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function reasonIsBorneOut(Customer $customer, PaymentData $data, array $attributes, string $reason, Payment $earlier): bool
    {
        if ($this->isLikelyDoubleEntry($earlier, (string) $data->amount, $data->collectedAt)) {
            return false;
        }

        return match ($reason) {
            'advance' => in_array($attributes['frequency'] ?? null, ['months', 'yearly'], true),
            'arrears' => (function () use ($customer, $data): bool {
                $customer->loadMissing('latestManuscript');
                $owed = bcadd((string) ($customer->latestManuscript?->total_arrears ?? '0'), (string) $customer->bill, 2);

                return bccomp((string) ($customer->latestManuscript?->total_arrears ?? '0'), '0', 2) > 0
                    && bccomp((string) $data->amount, $owed, 2) <= 0;
            })(),
            default => false,
        };
    }

    /** The same amount as the earlier payment, within 3 days of it. */
    private function isLikelyDoubleEntry(Payment $earlier, string $amount, ?string $collectedAt): bool
    {
        $earlierAt = Carbon::parse($earlier->collected_at ?? $earlier->created_at);
        $thisAt = $collectedAt !== null ? Carbon::parse($collectedAt) : Carbon::now();

        return bccomp((string) $earlier->amount, $amount, 2) === 0 && $earlierAt->diffInDays($thisAt, true) <= 3;
    }

    /**
     * For a batch (offline sync): the earlier payments of every customer and
     * month in it, in one query, so the duplicate check reads memory instead
     * of the database once per payment. Payments the batch creates join it,
     * so two copies inside one push are caught too. forgetEarlierPayments()
     * ends it.
     *
     * @param  list<int>  $customerIds
     * @param  list<?string>  $collectedAts
     */
    public function primeEarlierPayments(array $customerIds, array $collectedAts): void
    {
        $months = collect($collectedAts === [] ? [null] : $collectedAts)
            ->map(fn (?string $at): Carbon => ($at !== null ? Carbon::parse($at) : Carbon::now())->setTimezone(BusinessTimezone::WAT));

        $this->earlierMemo = [];

        if ($customerIds === []) {
            return;
        }

        Payment::query()
            ->with(['receipt', 'recordedBy'])
            ->whereIn('customer_id', $customerIds)
            ->subscription()
            ->where('verification_status', '!=', 'rejected')
            ->whereRaw('coalesce(collected_at, created_at) between ? and ?', [
                $months->min()->copy()->startOfMonth()->setTimezone('UTC'),
                $months->max()->copy()->endOfMonth()->setTimezone('UTC'),
            ])
            ->orderByRaw('coalesce(collected_at, created_at) asc')
            ->get()
            ->each(fn (Payment $payment) => $this->rememberEarlier($payment));

        // Every (customer, month) of the batch is now known: absent = none.
        foreach ($customerIds as $customerId) {
            foreach ($months as $month) {
                $this->earlierMemo[$customerId.'|'.$month->format('Y-m')] ??= null;
            }
        }
    }

    public function forgetEarlierPayments(): void
    {
        $this->earlierMemo = null;
    }

    /**
     * The customer's latest subscription payment (not rejected) collected in
     * the same calendar month (business time) as $collectedAt, or now.
     */
    public function earlierPaymentThisMonth(Customer $customer, ?string $collectedAt): ?Payment
    {
        $moment = ($collectedAt !== null ? Carbon::parse($collectedAt) : Carbon::now())->setTimezone(BusinessTimezone::WAT);
        $key = $customer->id.'|'.$moment->format('Y-m');

        if ($this->earlierMemo !== null && array_key_exists($key, $this->earlierMemo)) {
            return $this->earlierMemo[$key];
        }

        return Payment::query()
            ->with(['receipt', 'recordedBy'])
            ->where('customer_id', $customer->id)
            ->subscription()
            ->where('verification_status', '!=', 'rejected')
            ->whereRaw('coalesce(collected_at, created_at) between ? and ?', [
                $moment->copy()->startOfMonth()->setTimezone('UTC'),
                $moment->copy()->endOfMonth()->setTimezone('UTC'),
            ])
            ->orderByRaw('coalesce(collected_at, created_at) desc')
            ->first();
    }

    /** Keeps a batch's memo current (the latest payment per customer and month wins). */
    private function rememberEarlier(Payment $payment): void
    {
        if ($this->earlierMemo === null || ! $payment->isSubscription() || $payment->verification_status === 'rejected') {
            return;
        }

        $month = Carbon::parse($payment->collected_at ?? $payment->created_at)->setTimezone(BusinessTimezone::WAT)->format('Y-m');
        $this->earlierMemo[$payment->customer_id.'|'.$month] = $payment;
    }

    /** "ABANG already paid 2,500 FCFA on 12 Oct (receipt 000231, recorded by DIVINE). …" */
    private function describeEarlier(Customer $customer, Payment $earlier, string $amount): string
    {
        $when = Carbon::parse($earlier->collected_at ?? $earlier->created_at)->setTimezone(BusinessTimezone::WAT);
        $details = array_filter([
            $earlier->receipt?->receipt_number !== null ? "receipt {$earlier->receipt->receipt_number}" : null,
            $earlier->recordedBy?->name !== null ? "recorded by {$earlier->recordedBy->name}" : null,
        ]);

        return ($this->isLikelyDoubleEntry($earlier, $amount, null) ? 'Possible double entry: ' : '')
            ."{$customer->name} already paid ".number_format((float) $earlier->amount, 0, '.', ',').' FCFA on '.$when->format('j M')
            .($details !== [] ? ' ('.implode(', ', $details).')' : '')
            .'. Say why another payment this month is fine, or cancel.';
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
        BranchLookup::remember([$customer]);
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
        BranchLookup::remember($customersByUuid);

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
        $this->assertNoMoneyChangeAfterReceipt($payment, $data);

        if (! $payment->isSubscription() && $data->frequency !== null && $data->frequency !== 'monthly') {
            throw ValidationException::withMessages(['frequency' => 'Only a subscription payment can buy prepaid months.']);
        }

        $attributes = $data->toAttributes();

        // Corrected into a prepayment: same rules as recording one (debt
        // first, the bill locked as its rate).
        $frequency = $data->frequency ?? $payment->frequency;
        if (in_array($frequency, ['months', 'yearly'], true)) {
            $attributes['clear_arrears_first'] = $this->clearsArrearsFirst($data);
            if ($payment->prepaid_rate === null) {
                $attributes['prepaid_rate'] = (string) $payment->customer->bill;
            }
        } elseif ($data->clearArrearsFirst !== null) {
            unset($attributes['clear_arrears_first']);
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

    /**
     * A verified payment has a receipt in the customer's hand: changing what
     * was paid underneath it would make the receipt lie. The fix is to
     * reverse it (PaymentVerificationService::reverse()) and record it again.
     * Notes and references stay editable.
     */
    private function assertNoMoneyChangeAfterReceipt(Payment $payment, PaymentData $data): void
    {
        if ($payment->isReversed()) {
            throw ValidationException::withMessages(['amount' => 'This payment was reversed and can no longer change. Record the correct payment again.']);
        }

        if ($payment->verification_status !== 'verified') {
            return;
        }

        $changesMoney = ($data->amount !== null && bccomp($data->amount, (string) $payment->amount, 2) !== 0)
            || ($data->credit !== null && bccomp($data->credit, (string) ($payment->credit ?? '0'), 2) !== 0)
            || ($data->method !== null && $data->method !== $payment->method)
            || ($data->frequency !== null && $data->frequency !== $payment->frequency)
            || ($data->months !== null && $data->months !== (int) $payment->months);

        if ($changesMoney) {
            throw ValidationException::withMessages([
                'amount' => 'This payment has a receipt. Reverse it and record it again.',
            ]);
        }
    }

    public function delete(Payment $payment): void
    {
        // A verified payment has a receipt and may already be on a bill:
        // deleting it would make the receipt vanish and leave the bill wrong.
        if ($payment->verification_status === 'verified') {
            throw ValidationException::withMessages([
                'payment' => 'This payment is verified and has a receipt — reverse it instead of deleting it.',
            ]);
        }

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
     * A prepayment always pays off what the customer owes first; only the
     * rest buys prepaid months (owner's rule, 2026-10-25: "debts should be
     * cleared before any prepayment"). No override — whatever a form or an
     * older phone sends. ManuscriptCalculator applies the same rule.
     */
    private function clearsArrearsFirst(PaymentData $data): bool
    {
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
