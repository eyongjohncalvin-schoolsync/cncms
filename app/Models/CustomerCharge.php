<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopesRouteBindingToBranch;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A one-off amount a customer owes outside the monthly bill
 * (docs/plans/installation-and-promotions.md, phase 1). Only `installation`
 * today.
 *
 * The balance is never stored: amount − the verified installation payments
 * linked to it (payments.customer_charge_id), so instalments (D2) just work.
 * It is shown on the customer page and as its own line on the bill, but it
 * is never part of total_bill / arrears and never a reason to disconnect
 * (D1). Written only by App\Services\CustomerChargeService.
 *
 * Lifecycle: open → paid (balance reaches zero) or open → waived (a waiver
 * approved by someone other than whoever asked for it). paid → open again
 * only if a payment that settled it is later rejected. `refunded` is phase
 * 2's "Refund installation" (D9).
 *
 * @property string $amount
 * @property string $status
 */
#[Fillable([
    'customer_id', 'kind', 'amount', 'standard_amount', 'status', 'reason', 'requested_by', 'approved_by', 'approved_at', 'paid_at',
    'waiver_status', 'waiver_reason', 'waiver_requested_by', 'waiver_requested_at', 'waiver_decided_by', 'waiver_decided_at',
    'waiver_rejection_reason', 'waived_amount',
    'installation_offer_id', 'approval_status', 'approval_rejection_reason',
    'refund_status', 'refund_amount', 'refund_reason', 'refund_requested_by', 'refund_requested_at',
    'refund_decided_by', 'refund_decided_at', 'refund_rejection_reason', 'refund_voucher_id',
])]
#[RouteKey('uuid')]
class CustomerCharge extends Model
{
    use Auditable, HasUuid, ScopesRouteBindingToBranch;

    public const string KIND_INSTALLATION = 'installation';

    /** @var list<string> */
    public const array KINDS = [self::KIND_INSTALLATION];

    public const string STATUS_OPEN = 'open';

    public const string STATUS_PAID = 'paid';

    public const string STATUS_WAIVED = 'waived';

    public const string STATUS_REFUNDED = 'refunded';

    public const string WAIVER_PENDING = 'pending';

    public const string WAIVER_APPROVED = 'approved';

    public const string WAIVER_REJECTED = 'rejected';

    /** Fee-override approval (D8) and the installation refund (D9) share these. */
    public const string DECISION_PENDING = 'pending';

    public const string DECISION_APPROVED = 'approved';

    public const string DECISION_REJECTED = 'rejected';

    /**
     * A charge has no direct zone — branch is reached through its Customer.
     */
    protected static function branchRouteBindingRelation(): ?string
    {
        return 'customer.zone';
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'standard_amount' => 'decimal:2',
            'waived_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'waiver_requested_at' => 'datetime',
            'waiver_decided_at' => 'datetime',
            'refund_amount' => 'decimal:2',
            'refund_requested_at' => 'datetime',
            'refund_decided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Every payment recorded against this charge (any verification status). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function waiverRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiver_requested_by');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(InstallationOffer::class, 'installation_offer_id');
    }

    /** The bon that pays the refund out (D9). */
    public function refundVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'refund_voucher_id');
    }

    public function refundRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refund_requested_by');
    }

    public function refundDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refund_decided_by');
    }

    /** Free months that come with this installation (D3). */
    public function freeMonthGrants(): HasMany
    {
        return $this->hasMany(FreeMonthGrant::class);
    }

    /** A fee below the offer's that still waits for admin/owner approval (D8). */
    public function hasPendingFeeApproval(): bool
    {
        return $this->approval_status === self::DECISION_PENDING;
    }

    public function hasPendingRefund(): bool
    {
        return $this->refund_status === self::DECISION_PENDING;
    }

    /** Paid in full or waived — what starts the installation's free months (D3). */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_WAIVED], true);
    }

    public function waiverDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiver_decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** What verified installation payments have paid toward this charge so far. */
    public function paidAmount(): string
    {
        return $this->sumPayments(['verified']);
    }

    /**
     * What is still owed. Zero once paid, waived or refunded; never
     * negative.
     */
    public function balance(): string
    {
        if (in_array($this->status, [self::STATUS_WAIVED, self::STATUS_REFUNDED], true)) {
            return '0.00';
        }

        $balance = bcsub((string) $this->amount, $this->paidAmount(), 2);

        return bccomp($balance, '0', 2) > 0 ? $balance : '0.00';
    }

    /**
     * What may still be recorded against the charge: the balance less any
     * instalment still awaiting verification — so two pending payments can
     * never add up to more than the fee.
     */
    public function recordableAmount(): string
    {
        if (! $this->isOpen()) {
            return '0.00';
        }

        $left = bcsub((string) $this->amount, $this->sumPayments(['verified', 'pending']), 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    /**
     * Moves the charge between open and paid to match its verified payments
     * — called after an installation payment is recorded verified, verified
     * later, rejected or deleted. A waived or refunded charge is final.
     *
     * A fee below the offer's that still waits for approval (D8) is never
     * "paid" — the agreed amount isn't settled yet. (Free months no longer
     * follow this status: D17 decides them at registration.)
     */
    public function refreshStatus(): void
    {
        if (! in_array($this->status, [self::STATUS_OPEN, self::STATUS_PAID], true)) {
            return;
        }

        $fullyPaid = ! $this->hasPendingFeeApproval()
            && bccomp($this->paidAmount(), (string) $this->amount, 2) >= 0;

        if ($fullyPaid && $this->status === self::STATUS_OPEN) {
            $this->update(['status' => self::STATUS_PAID, 'paid_at' => now()]);
        } elseif (! $fullyPaid && $this->status === self::STATUS_PAID) {
            $this->update(['status' => self::STATUS_OPEN, 'paid_at' => null]);
        }
    }

    /**
     * @param  list<string>  $statuses
     */
    private function sumPayments(array $statuses): string
    {
        $sum = $this->payments()
            ->where('purpose', Payment::PURPOSE_INSTALLATION)
            ->whereIn('verification_status', $statuses)
            ->sum('amount');

        return bcadd((string) ($sum ?: '0'), '0', 2);
    }

    public function hasPendingWaiver(): bool
    {
        return $this->waiver_status === self::WAIVER_PENDING;
    }
}
