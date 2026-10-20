<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopesRouteBindingToBranch;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
])]
#[RouteKey('uuid')]
class Payment extends Model
{
    use Auditable, HasUuid, ScopesRouteBindingToBranch;

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

    /**
     * Payment has no direct zone — branch is reached through its Customer.
     */
    protected static function branchRouteBindingRelation(): ?string
    {
        return 'customer.zone';
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credit' => 'decimal:2',
            'prepaid_rate' => 'decimal:2',
            'clear_arrears_first' => 'boolean',
            'expiration_date' => 'date',
            'processed_at' => 'datetime',
            'collected_at' => 'datetime',
            'recorded_offline' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
        return $query
            ->where('verification_status', 'verified')
            ->where(fn ($inner) => $inner->whereNull('processed_period')->orWhere('processed_period', $period));
    }
}
