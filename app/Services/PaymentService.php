<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\PaymentData;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PrepaymentOffer;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Support\Billing\FreeMonthPlan;
use App\Support\BranchLookup;
use App\Support\BranchPrices;
use App\Support\BusinessTimezone;
use App\Support\CacheVersion;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentService
{
    /** First half of the per-customer advisory lock key (the customer id is the second). */
    private const int PAYMENT_LOCK_KEY = 7301;

    /**
     * Earlier payments of a batch (primeEarlierPayments()), keyed
     * "customerId|Y-m"; null outside a batch, when each check queries.
     *
     * @var array<string, ?Payment>|null
     */
    private ?array $earlierMemo = null;

    /**
     * Every payment of a batch's customers from 3 days before its first
     * month on, for sameAmountAcrossMonthEnd(); keyed by customer id. Null
     * outside a batch, like $earlierMemo.
     *
     * @var array<int, list<Payment>>|null
     */
    private ?array $monthEndMemo = null;

    /** Off while recording a correction (createCorrection()). */
    private bool $deskReceipts = true;

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
        // CacheVersion: any payment change (web, API, sync, jobs) retires the cached pages.
        $cacheKey = 'payments:list:'.CacheVersion::of(CacheVersion::PAYMENTS).':'.($this->context->branchId ?? 'all').':'
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
            self::showCacheKey($uuid, $this->context->branchId),
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

        // Cast: Redis keeps a bare number raw, so a cache hit comes back as "5", not 5.
        return (int) Cache::remember(
            'payments:pending_count:'.CacheVersion::of(CacheVersion::PAYMENTS).':'.($branchId ?? 'all'),
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

        $heldReason = $this->holdReason($customer, $data);

        $this->assertValidMethod($data, customerId: $customer->id);
        $this->assertValidPurpose($data);

        $attributes = $data->toAttributes();
        $attributes['verification_status'] = $canAutoVerify && $heldReason === null ? 'verified' : 'pending';
        $attributes['held_reason'] = $heldReason;
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

        // Two payments for the same customer at the same moment (two
        // collectors, two office tabs) would both pass the duplicate check
        // and both save unflagged. One customer's payments are recorded one
        // at a time instead: the second waits for the first to commit, then
        // sees it. Other customers never wait.
        $payment = DB::transaction(function () use ($customer, $data, $attributes): Payment {
            DB::select('select pg_advisory_xact_lock(?, ?)', [self::PAYMENT_LOCK_KEY, $customer->id]);

            $attributes = $this->flagPossibleDuplicate($customer, $data, $attributes);

            return $data->receiptNumber !== null
                ? $this->createWithFieldReceipt($customer, $data, $attributes)
                : $this->knownRelations($this->payments->create($customer->id, $attributes), $customer)->setRelation('receipt', null);
        });

        if ($data->receiptNumber !== null) {
            $this->receipts->autoSend($payment->receipt, $payment);

            // A verified instalment with a field receipt (an offline field
            // installation, phase 3) may settle its charge too.
            if ($payment->verification_status === 'verified') {
                $payment->customerCharge?->refreshStatus();
            }

            $this->rememberEarlier($payment);
            $this->receiveAtDesk($payment);

            return $payment;
        }

        $this->rememberEarlier($payment);
        $this->receiveAtDesk($payment);

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
     * This business month's payments, not rejected, for customers in the
     * zones the caller covers, that someone else recorded (Api\
     * PaymentController::byOthers()). Empty for anyone not zone-scoped.
     *
     * @return EloquentCollection<int, Payment>
     */
    public function byOthersInMyZones(User $user): EloquentCollection
    {
        $zoneIds = TenantContext::currentZoneIds();

        if ($zoneIds === null || $zoneIds === []) {
            return new EloquentCollection;
        }

        [$monthStart] = BusinessTimezone::periodBoundsUtc(BusinessTimezone::currentPeriod());

        return Payment::query()
            ->with(['customer:id,uuid,name,zone_id', 'recordedBy:id,name', 'receipt'])
            ->whereHas('customer', fn ($query) => $query->whereIn('zone_id', $zoneIds))
            ->where('recorded_by', '!=', $user->id)
            ->where('verification_status', '!=', 'rejected')
            ->whereRaw('coalesce(collected_at, created_at) >= ?', [$monthStart])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
    }

    /**
     * A correction recorded in a reversed payment's place
     * (PaymentVerificationService::reverse()): its money is the reversed
     * payment's, already counted or still owed — never received at the desk
     * a second time.
     */
    public function createCorrection(PaymentData $data): Payment
    {
        $this->deskReceipts = false;

        try {
            return $this->create($data);
        } finally {
            $this->deskReceipts = true;
        }
    }

    /** Cash taken at the desk goes straight into the recorder's till (RemittanceService::receiveAtDesk()). */
    private function receiveAtDesk(Payment $payment): void
    {
        $recorder = auth()->user();

        if (! $this->deskReceipts || ! $recorder instanceof User) {
            return;
        }

        // The payment is saved either way: if the till can't take it, its
        // recorder hands it over like any collector.
        try {
            app(RemittanceService::class)->receiveAtDesk($payment, $recorder);
        } catch (Throwable $e) {
            report($e);
        }
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
        $offer = PrepaymentOffer::query()->where('uuid', $data->prepaymentOfferUuid)->first();
        // On sale on the day the money was taken: today at the counter, the
        // collection day for a phone (which may have sold it offline just
        // before it ended — then it is kept, but the office decides).
        $soldOn = $data->collectedAt !== null
            ? Carbon::parse($data->collectedAt)->setTimezone(BusinessTimezone::WAT)->toDateString()
            : BusinessTimezone::today();
        $onSale = $offer !== null && PrepaymentOffer::query()->whereKey($offer->id)->available($soldOn)->exists();

        if ($offer === null || (! $onSale && ! $offline)) {
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
            ! $onSale => "{$offer->name} was not on sale on {$soldOn}.",
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
        return self::unpaidDebts(collect([$customer]))[$customer->id];
    }

    /**
     * unpaidDebt() for many customers in one query — the web payment form
     * and the phone's customer download show the same figure the server
     * checks an offer payment against.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<int, string> customer id => unpaid debt (never negative)
     */
    public static function unpaidDebts(Collection $customers): array
    {
        // One query for any not yet loaded (a per-model loadMissing would be one each).
        (new EloquentCollection($customers->all()))->loadMissing('latestManuscript');

        $paidSince = Payment::query()
            ->whereIn('customer_id', $customers->pluck('id'))
            ->subscription()
            ->whereNull('processed_at')
            ->whereNull('prepayment_offer_id')
            ->where('verification_status', '!=', 'rejected')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, sum(amount) as paid')
            ->pluck('paid', 'customer_id');

        return $customers->mapWithKeys(function (Customer $customer) use ($paidSince): array {
            $debt = bcsub(
                bcsub((string) ($customer->latestManuscript?->total_arrears ?? '0'), (string) ($customer->latestManuscript?->credit ?? '0'), 2),
                (string) ($paidSince[$customer->id] ?? '0'),
                2,
            );

            return [$customer->id => bccomp($debt, '0', 2) > 0 ? $debt : '0.00'];
        })->all();
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

        $earlier = $this->earlierPaymentFor($customer, (string) $data->amount, $data->collectedAt);

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
            // Only debt still unpaid counts — not debt the earlier payment already cleared.
            'arrears' => (function () use ($customer, $data): bool {
                $debt = $this->unpaidDebt($customer);

                return bccomp($debt, '0', 2) > 0
                    && bccomp((string) $data->amount, bcadd($debt, (string) $customer->bill, 2), 2) <= 0;
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
        $this->monthEndMemo = [];

        if ($customerIds === []) {
            return;
        }

        $firstMonthStart = $months->min()->copy()->startOfMonth();

        // From 3 days before the first month, so a month-end double entry is caught from memory too.
        Payment::query()
            ->with(['receipt', 'recordedBy'])
            ->whereIn('customer_id', $customerIds)
            ->subscription()
            ->where('verification_status', '!=', 'rejected')
            ->whereRaw('coalesce(collected_at, created_at) between ? and ?', [
                $firstMonthStart->copy()->subDays(3)->setTimezone('UTC'),
                $months->max()->copy()->endOfMonth()->setTimezone('UTC'),
            ])
            ->orderByRaw('coalesce(collected_at, created_at) asc')
            ->get()
            ->each(function (Payment $payment) use ($firstMonthStart): void {
                if (Carbon::parse($payment->collected_at ?? $payment->created_at)->gte($firstMonthStart)) {
                    $this->rememberEarlier($payment);
                } else {
                    $this->monthEndMemo[$payment->customer_id][] = $payment;
                }
            });

        foreach ($customerIds as $customerId) {
            $this->monthEndMemo[$customerId] ??= [];
        }

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
        $this->monthEndMemo = null;
    }

    /**
     * The earlier payment a new one of $amount may repeat — this month's,
     * or the same amount just before the month began. What saving checks
     * (flagPossibleDuplicate()) and what the phone asks before saving when
     * it has signal (owner, 2026-10-28), so both see every collector's.
     */
    public function earlierPaymentFor(Customer $customer, string $amount, ?string $collectedAt = null): ?Payment
    {
        return $this->earlierPaymentThisMonth($customer, $collectedAt)
            ?? $this->sameAmountAcrossMonthEnd($customer, $amount, $collectedAt);
    }

    /**
     * Each customer's latest subscription payment (not rejected) this
     * business month, whoever recorded it — one query for the whole set.
     * The phone keeps it with the customer, so it can warn offline about a
     * payment the office or another collector already took.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<int, array{amount: string, collected_at: string, recorded_by: ?string}>
     */
    public static function latestThisMonth(Collection $customers): array
    {
        if ($customers->isEmpty()) {
            return [];
        }

        $monthStart = Carbon::now()->setTimezone(BusinessTimezone::WAT)->startOfMonth()->setTimezone('UTC');

        $payments = Payment::query()
            ->withoutGlobalScope(BranchScope::class)
            ->selectRaw('distinct on (payments.customer_id) payments.*')
            ->whereIn('customer_id', $customers->pluck('id'))
            ->subscription()
            ->where('verification_status', '!=', 'rejected')
            ->whereRaw('coalesce(collected_at, created_at) >= ?', [$monthStart])
            ->orderBy('customer_id')
            ->orderByRaw('coalesce(collected_at, created_at) desc')
            ->get();

        $names = User::query()->whereKey($payments->pluck('recorded_by')->filter()->unique())->pluck('name', 'id');

        return $payments->mapWithKeys(fn (Payment $payment): array => [(int) $payment->customer_id => [
            'amount' => (string) $payment->amount,
            'collected_at' => ($payment->collected_at ?? $payment->created_at)->toIso8601String(),
            'recorded_by' => $names[$payment->recorded_by] ?? null,
        ]])->all();
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

    /**
     * "Not a duplicate": stamps every payment of $payment's customer in that
     * month (not rejected), so the month leaves the Possible duplicates tab.
     * A payment recorded later that month brings it back. Saved one by one
     * so the audit log records each.
     */
    public function clearDuplicateMonth(Payment $payment, User $actor): void
    {
        DB::transaction(function () use ($payment, $actor): void {
            Payment::query()->sameMonthAs($payment)
                ->where('verification_status', '!=', 'rejected')
                ->whereNull('duplicate_cleared_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (Payment $paid) => $paid->update(['duplicate_cleared_at' => now(), 'duplicate_cleared_by' => $actor->id]));
        });
    }

    /**
     * The classic double entry straddling a month end: the same amount on
     * 31 Oct and again on 1 Nov is two months to earlierPaymentThisMonth().
     * Only looked for in a month's first 3 days (the double-entry window),
     * so most payments cost no extra query.
     */
    private function sameAmountAcrossMonthEnd(Customer $customer, string $amount, ?string $collectedAt): ?Payment
    {
        $moment = ($collectedAt !== null ? Carbon::parse($collectedAt) : Carbon::now())->setTimezone(BusinessTimezone::WAT);

        if ($moment->day > 3) {
            return null;
        }

        $from = $moment->copy()->subDays(3);
        $to = $moment->copy()->startOfMonth();

        // A batch's customer: read memory (primeEarlierPayments() + payments the batch created).
        if ($this->monthEndMemo !== null && array_key_exists($customer->id, $this->monthEndMemo)) {
            return collect($this->monthEndMemo[$customer->id])
                ->filter(function (Payment $payment) use ($amount, $from, $to): bool {
                    $at = Carbon::parse($payment->collected_at ?? $payment->created_at);

                    return bccomp((string) $payment->amount, $amount, 2) === 0 && $at->between($from, $to);
                })
                ->sortByDesc(fn (Payment $payment) => Carbon::parse($payment->collected_at ?? $payment->created_at)->getTimestamp())
                ->first();
        }

        return Payment::query()
            ->with(['receipt', 'recordedBy'])
            ->where('customer_id', $customer->id)
            ->subscription()
            ->where('verification_status', '!=', 'rejected')
            ->where('amount', $amount)
            ->whereRaw('coalesce(collected_at, created_at) between ? and ?', [
                $moment->copy()->subDays(3)->setTimezone('UTC'),
                $moment->copy()->startOfMonth()->setTimezone('UTC'),
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

        // Only for a primed customer: an unprimed one's list would be incomplete.
        if ($this->monthEndMemo !== null && array_key_exists($payment->customer_id, $this->monthEndMemo)) {
            $this->monthEndMemo[$payment->customer_id][] = $payment;
        }
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
            .'. Say why another payment is fine, or cancel.';
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
     * written in ONE transaction — createForCustomer()'s, which this always
     * runs inside: a refused receipt stores nothing, and a number can never
     * end up used without its payment or vice versa. The caller emails it
     * once committed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createWithFieldReceipt(Customer $customer, PaymentData $data, array $attributes): Payment
    {
        BranchLookup::remember([$customer]);
        $actor = auth()->user();

        $claim = $this->fieldReceipts->claim(
            $actor,
            $customer,
            (string) $data->amount,
            (string) $data->receiptNumber,
            $data->securityCode,
            $data->receiptIssuedAt,
        );

        $payment = $this->knownRelations($this->payments->create($customer->id, $attributes), $customer);

        $this->receipts->issueField($payment, $claim['leaf'], $claim['issued_at'], $claim['device'], $data->securityCode, $actor);

        return $payment;
    }

    /**
     * A payment just created already has its customer, no verification and
     * the signed-in recorder — set them so issuing its receipt loads none.
     */
    private function knownRelations(Payment $payment, Customer $customer): Payment
    {
        return $payment->setRelation('customer', $customer)
            ->setRelation('verification', null)
            ->setRelation('recordedBy', auth()->user());
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
        // Each payment is the customer's one monthly bill: labelled as months
        // or a year, it would claim months its money never bought.
        if ($frequency !== 'monthly') {
            throw ValidationException::withMessages(['frequency' => 'Bulk entry is for monthly payments only — record a prepayment on its own.']);
        }

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
        $this->assertValidMethod($data, $payment, $payment->customer_id);
        $this->assertNoMoneyChangeAfterReceipt($payment, $data);
        $this->assertNotHandedOver($payment, $data);

        // An offer buys exactly its months: "4 months for 10,000" never becomes 12.
        if ($payment->prepayment_offer_id !== null
            && (($data->frequency !== null && $data->frequency !== $payment->frequency)
                || ($data->months !== null && $data->months !== (int) $payment->months))) {
            throw ValidationException::withMessages(['months' => 'This payment is under an offer — its months are the offer\'s. Reject it and record it again to change them.']);
        }

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
    private function assertValidMethod(PaymentData $data, ?Payment $existing = null, ?int $customerId = null): void
    {
        $method = $data->method ?? $existing?->method ?? 'cash';
        $reference = $data->methodReference ?? $existing?->method_reference;

        if (! in_array($method, Payment::METHODS, true)) {
            throw ValidationException::withMessages(['method' => "Unknown payment method [{$method}]."]);
        }

        if (in_array($method, Payment::METHODS_REQUIRING_REFERENCE, true) && ($reference === null || $reference === '')) {
            throw ValidationException::withMessages([
                'method_reference' => $method === 'bank' ? 'Enter the bank slip number.' : 'Enter the '.Payment::METHOD_LABELS[$method].' transaction ID.',
            ]);
        }

        // From a phone the money was already collected: held, not refused (holdReason()).
        if ($data->recordedOffline === true) {
            return;
        }

        // The office: whoever verifies payments or receives cash — never a field agent.
        if ($method === 'bank' && ! $this->context->canAny('payments.verify', 'cash.receive')) {
            throw ValidationException::withMessages(['method' => 'Bank payments are recorded by the office.']);
        }

        $usedBy = $this->paymentWithReference($method, $reference, $customerId, $existing);

        if ($usedBy !== null) {
            throw ValidationException::withMessages([
                'method_reference' => "This transaction is already on {$usedBy->customer?->name}'s payment of ".number_format((float) $usedBy->amount, 0, '.', ',').' FCFA — one transaction pays once.',
            ]);
        }
    }

    /**
     * Another payment the same MoMo / Orange transaction or bank slip already
     * stands for, for another customer (owner, 2026-10-28: one transaction
     * pays once). One customer's own transfer may cover two payments made
     * together (a reconnection fine and arrears); a real second payment of
     * theirs is flagged as a possible duplicate instead. Rejected payments
     * don't count — their money was refunded, corrected or never taken.
     */
    private function paymentWithReference(string $method, ?string $reference, ?int $customerId, ?Payment $existing = null): ?Payment
    {
        if (! in_array($method, Payment::NON_CASH_METHODS, true) || $reference === null || trim($reference) === '') {
            return null;
        }

        return Payment::query()->withoutGlobalScope(BranchScope::class)
            ->with('customer:id,name')
            ->where('method', $method)
            ->whereRaw('lower(trim(method_reference)) = ?', [mb_strtolower(trim($reference))])
            ->where('verification_status', '!=', 'rejected')
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', '!=', $customerId))
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
            ->first();
    }

    /**
     * Why a phone payment is taken but held for the office instead of
     * refused — the money was already collected, and a refused payment
     * would sit on the phone where nobody sees it (owner, 2026-10-28).
     */
    private function holdReason(Customer $customer, PaymentData $data): ?string
    {
        if ($data->recordedOffline !== true) {
            return null;
        }

        $method = $data->method ?? 'cash';

        if (in_array($customer->status, ['disconnected', 'suspended'], true)) {
            return "The customer was {$customer->status} when this came in — reconnect them, or refund the money.";
        }

        if ($method === 'bank') {
            return 'A bank payment recorded on a phone — bank payments are recorded by the office. Check the slip.';
        }

        $usedBy = $this->paymentWithReference($method, $data->methodReference, $customer->id);

        if ($usedBy !== null) {
            return "Transaction {$data->methodReference} is already on {$usedBy->customer?->name}'s payment — one transaction pays once.";
        }

        return null;
    }

    /**
     * Cash controls: a payment's amount and method are what its collector
     * owes a handover for — changing them, before or after the handover,
     * would quietly change what they owe (owner, 2026-10-28). A mistake is
     * rejected, saying whether money was taken, and recorded again. Other
     * corrections (frequency, months) stay allowed.
     */
    private function assertNotHandedOver(Payment $payment, PaymentData $data): void
    {
        $changesMoney = ($data->amount !== null && bccomp($data->amount, (string) $payment->amount, 2) !== 0)
            || ($data->method !== null && $data->method !== $payment->method);

        if (! $changesMoney) {
            return;
        }

        if ($payment->remittanceLine()->exists()) {
            throw ValidationException::withMessages([
                'amount' => 'This payment\'s money has already been handed over in a daily remittance — its amount and method can no longer change.',
            ]);
        }

        if ($payment->isUnderCashControls()) {
            throw ValidationException::withMessages([
                'amount' => 'The collector owes a handover for this amount and method, so they can\'t change. Reject it — saying whether money was taken — and record it again.',
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

        // A field receipt is printed before the office verifies: the
        // customer's paper already says what was paid.
        if ($payment->verification_status !== 'verified' && ! $payment->receipt()->exists()) {
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

        // Deleting it would quietly lower what its collector owes (owner,
        // 2026-10-28) — rejecting it keeps the record and asks about the money.
        if ($payment->isUnderCashControls()) {
            throw ValidationException::withMessages([
                'payment' => 'Its collector owes a handover for this payment — reject it instead, saying whether money was taken.',
            ]);
        }

        // A receipt number once issued is audited (printed books, unaccounted
        // numbers): a reversed payment keeps its VOID receipt as proof and
        // simply stays under Rejected.
        if ($payment->receipt()->exists()) {
            throw ValidationException::withMessages([
                'payment' => 'This payment had a receipt — it stays, voided, as proof. It only shows under Rejected.',
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
        Cache::forget(self::showCacheKey($uuid, $this->context->branchId));
        Cache::forget(self::showCacheKey($uuid, null));
    }

    /** The payment page's cache key — its version moves with every payment change. */
    public static function showCacheKey(string $uuid, ?int $branchId): string
    {
        return "payments:show:{$uuid}:".CacheVersion::of(CacheVersion::PAYMENTS).':'.($branchId ?? 'all');
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
