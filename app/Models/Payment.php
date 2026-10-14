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
    // How the money came in, and the mobile-money reference (unique).
    'method', 'momo_ref',
])]
#[RouteKey('uuid')]
class Payment extends Model
{
    use Auditable, HasUuid, ScopesRouteBindingToBranch;

    public const string METHOD_CASH = 'cash';

    public const string METHOD_MTN_MOMO = 'mtn_momo';

    public const string METHOD_ORANGE_MONEY = 'orange_money';

    public const string METHOD_BANK = 'bank';

    public const array METHODS = [self::METHOD_CASH, self::METHOD_MTN_MOMO, self::METHOD_ORANGE_MONEY, self::METHOD_BANK];

    /** Methods that carry a mobile-money transaction reference. */
    public const array MOMO_METHODS = [self::METHOD_MTN_MOMO, self::METHOD_ORANGE_MONEY];

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

    /**
     * Whether a MoMo reference is already on another payment (references
     * are unique, case-insensitively — payments_momo_ref_unique).
     */
    public static function momoRefTaken(string $momoRef): bool
    {
        return static::query()->whereRaw('lower(momo_ref) = ?', [mb_strtolower(trim($momoRef))])->exists();
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
