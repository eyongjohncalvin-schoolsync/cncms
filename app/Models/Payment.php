<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopesRouteBindingToBranch;
use App\Models\Scopes\BranchScope;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable([
    'customer_id', 'amount', 'credit', 'frequency', 'expiration_date', 'months', 'prepaid_rate', 'clear_arrears_first',
    'verification_status', 'processed_at', 'processed_period', 'recorded_offline', 'recorded_by_device', 'local_uuid', 'recorded_by',
    // The field agent's actual offline-collection timestamp — see the
    // add_collected_at_to_payments_table migration and
    // App\Services\SyncService::pushPayment()'s doc comment. Deliberately
    // separate from `created_at` (server-arrival time, untouched). Always
    // null for a web-recorded payment; only pushPayment() ever populates
    // it.
    'collected_at',
    // How the money came in + the operator transaction ID — see METHODS.
    'method', 'method_reference',
    // What the money is for — see PURPOSES. Set only by server-side
    // callers (PaymentService, CustomerStatusService, CustomerChargeService),
    // never accepted from a payment form.
    'purpose', 'customer_charge_id',
    // Installation phase 2 (D15): a "paid upfront" payment's months per
    // service, each at that service's locked price — [{service_id, months, rate}].
    'service_months',
    // A possible repeat of an earlier payment that month — see DUPLICATE_REASONS.
    'duplicate_of_id', 'duplicate_reason', 'duplicate_note',
    // The prepayment offer it bought (PaymentService::applyPrepaymentOffer()).
    'prepayment_offer_id',
])]
#[RouteKey('uuid')]
class Payment extends Model
{
    use Auditable, BelongsToBranch, HasUuid, ScopesRouteBindingToBranch;

    /** The monthly bill — the only money billing ever sees. */
    public const string PURPOSE_SUBSCRIPTION = 'subscription';

    /** Pays an installation charge (customer_charge_id), possibly in instalments. */
    public const string PURPOSE_INSTALLATION = 'installation';

    /** The reconnection fine, owed to the technician (D13) — pass-through, never income. */
    public const string PURPOSE_TECHNICIAN_FEE = 'technician_fee';

    /**
     * What a payment is for (docs/plans/installation-and-promotions.md,
     * phase 1). Defaults to `subscription` at the column, so every existing
     * payment and every older mobile build keeps its meaning.
     *
     * @var list<string>
     */
    public const array PURPOSES = [self::PURPOSE_SUBSCRIPTION, self::PURPOSE_INSTALLATION, self::PURPOSE_TECHNICIAN_FEE];

    /** @var array<string, string> */
    public const array PURPOSE_LABELS = [
        self::PURPOSE_SUBSCRIPTION => 'Subscription',
        self::PURPOSE_INSTALLATION => 'Installation',
        self::PURPOSE_TECHNICIAN_FEE => 'Technician motivation fee',
    ];

    /**
     * Why a second subscription payment in the same month is fine, as the
     * collector confirmed it — or `unconfirmed` when an offline phone sent it
     * without knowing of the earlier one. Either way it is never verified on
     * the spot; someone else checks it.
     *
     * @var array<string, string>
     */
    public const array DUPLICATE_REASONS = [
        'advance' => 'Paying next month(s) in advance',
        'arrears' => 'Paying old debt',
        'refund' => "Customer's mistake — will be refunded",
        'other' => 'Other',
        'unconfirmed' => 'Not confirmed (recorded offline)',
    ];

    /** The reasons a collector may pick (the system sets `unconfirmed`). */
    public const array DUPLICATE_REASONS_PICKABLE = ['advance', 'arrears', 'refund', 'other'];

    /**
     * The heading printed on the receipt for each purpose.
     *
     * @var array<string, string>
     */
    public const array RECEIPT_TITLES = [
        self::PURPOSE_SUBSCRIPTION => 'PAYMENT RECEIPT',
        self::PURPOSE_INSTALLATION => 'INSTALLATION RECEIPT',
        self::PURPOSE_TECHNICIAN_FEE => 'TECHNICIAN MOTIVATION FEE',
    ];

    /**
     * Mirrors the column default so a just-created model already knows it
     * is a subscription payment without a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'purpose' => self::PURPOSE_SUBSCRIPTION,
    ];

    /**
     * A payment's purpose (and the charge it pays) cannot change once it has
     * been verified, receipted or handed over — the receipt, the bill run
     * and the cash book all relied on it. A correction is a reject/void and
     * a new payment.
     */
    protected static function booted(): void
    {
        static::updating(function (Payment $payment): void {
            if (! $payment->isDirty(['purpose', 'customer_charge_id'])) {
                return;
            }

            $locked = $payment->getOriginal('verification_status') === 'verified'
                || $payment->receipt()->exists()
                || $payment->remittanceLine()->exists();

            if ($locked) {
                throw new LogicException('A payment\'s purpose cannot change once it has been verified, receipted or handed over — reject it and record a new one.');
            }
        });
    }

    /**
     * How a customer paid (docs/plans/cash-controls.md). Cash sits in the
     * collector's hand until it is remitted; the two mobile-money operators
     * pay straight into the company wallet and must carry the operator's
     * transaction ID (method_reference) so the cashier can tick it off the
     * wallet statement.
     *
     * @var list<string>
     */
    public const array METHODS = ['cash', 'mtn_momo', 'orange_money', 'bank'];

    /** @var list<string> */
    public const array METHODS_REQUIRING_REFERENCE = ['mtn_momo', 'orange_money'];

    /** @var array<string, string> */
    public const array METHOD_LABELS = [
        'cash' => 'Cash',
        'mtn_momo' => 'MTN MoMo',
        'orange_money' => 'Orange Money',
        'bank' => 'Bank',
    ];

    /**
     * The shared validation rules for method + reference, used by every
     * payment-entry request (web, API) so the two never drift.
     *
     * @return array<string, list<string>>
     */
    public static function methodRules(): array
    {
        return [
            'method' => ['nullable', 'string', 'in:'.implode(',', self::METHODS)],
            'method_reference' => ['nullable', 'string', 'max:100', 'required_if:method,'.implode(',', self::METHODS_REQUIRING_REFERENCE)],
        ];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credit' => 'decimal:2',
            'prepaid_rate' => 'decimal:2',
            'clear_arrears_first' => 'boolean',
            'service_months' => 'array',
            'expiration_date' => 'date',
            'processed_at' => 'datetime',
            'collected_at' => 'datetime',
            'recorded_offline' => 'boolean',
        ];
    }

    /** The prepayment offer this payment bought, if any. */
    public function prepaymentOffer(): BelongsTo
    {
        return $this->belongsTo(PrepaymentOffer::class);
    }

    /** The earlier payment that month this one may repeat. */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function isPossibleDuplicate(): bool
    {
        return $this->duplicate_of_id !== null;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScope(BranchScope::class);
    }

    /**
     * The staff member who recorded the payment (null on payments recorded
     * before this was captured). Cross-schema — User is pinned to the
     * central connection.
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verification(): HasOne
    {
        return $this->hasOne(PaymentVerification::class);
    }

    /**
     * The business-issued receipt for this payment (Wave 2 of
     * docs/plans/payment-receipts-and-whatsapp.md). At most one row —
     * `payment_receipts.payment_id` is UNIQUE — auto-issued by
     * App\Services\PaymentVerificationService::verify() on approval, voided
     * (never deleted) on a later rejection. Distinct from verification()'s
     * `receipt_photo_path`, which is proof-of-payment evidence.
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }

    /** The daily-income handover this payment's money went through, if any (cash controls). */
    public function remittanceLine(): HasOne
    {
        return $this->hasOne(RemittanceLine::class);
    }

    /**
     * The single, canonical "eligible income for period P" predicate —
     * previously duplicated verbatim in TWO places
     * (App\Support\ScheduledTasks\ManuscriptChunkDataResolver::resolve() and
     * App\Console\Commands\ManuscriptCalculate::runForEveryCustomer(), each
     * one's own doc comment flagging the other as the place that must stay
     * in lockstep with it) and about to be needed by a THIRD
     * (App\Services\ManuscriptPreRunReviewService's "who hasn't paid" review
     * list) — factored here once so there is exactly one definition of
     * "eligible" for all three callers to share, closing the duplication
     * instead of extending it.
     *
     * A payment is eligible for period P when it is `verification_status =
     * 'verified'` AND it has never yet been consumed by any period's
     * calculation (`processed_period IS NULL` — this is what lets a frozen
     * customer's payment carry forward untouched across however many
     * disconnected/passive/prepaid periods pass before it's finally
     * consumed) OR it was already consumed by this SAME period P
     * (`processed_period = P`), which is what makes re-running P idempotent.
     * See App\Services\ManuscriptCalculator's class doc for the full
     * rationale — this scope exists purely so that rationale has one place
     * to actually live as code.
     */
    public function scopeEligibleForPeriod(Builder $query, string $period): Builder
    {
        // Installation and technician-fee money never pays a bill
        // (docs/plans/installation-and-promotions.md, phase 1).
        return $query
            ->where($query->qualifyColumn('purpose'), self::PURPOSE_SUBSCRIPTION)
            ->where('verification_status', 'verified')
            ->where(fn ($inner) => $inner->whereNull('processed_period')->orWhere('processed_period', $period));
    }

    /**
     * Subscription money only — the monthly bill. Every read that feeds a
     * bill, a balance, arrears, credit, a collection rate or a "has paid"
     * check goes through this.
     */
    public function scopeSubscription(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('purpose'), self::PURPOSE_SUBSCRIPTION);
    }

    /**
     * Company income — everything except the technician's pass-through fee.
     */
    public function scopeIncome(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('purpose'), '!=', self::PURPOSE_TECHNICIAN_FEE);
    }

    public function isSubscription(): bool
    {
        return ($this->purpose ?? self::PURPOSE_SUBSCRIPTION) === self::PURPOSE_SUBSCRIPTION;
    }

    /** The installation charge this payment pays, when purpose = installation. */
    public function customerCharge(): BelongsTo
    {
        return $this->belongsTo(CustomerCharge::class);
    }

    /** The cashier's payout to the technician that settled this fee, when purpose = technician_fee. */
    public function technicianFeePayout(): BelongsTo
    {
        return $this->belongsTo(TechnicianFeePayout::class);
    }

    /** Its branch comes from its customer (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'customer';
    }

    /** Customer history: stays whole for its customer; fenced on lists and links instead (BelongsToBranch). */
    protected static function fencedByBranchScope(): bool
    {
        return false;
    }
}
