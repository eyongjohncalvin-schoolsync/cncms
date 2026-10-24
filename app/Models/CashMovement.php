<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One line of the cash book: money into or out of one CashAccount
 * (docs/plans/cash-controls.md, wave 4). Append-only — written only by
 * App\Services\CashBookService, never updated or deleted; a correction is a
 * new row pointing at the one it reverses (reversal_of_id).
 */
#[Fillable([
    'cash_account_id', 'direction', 'amount', 'kind', 'document_number', 'source_type', 'source_id',
    'counterpart_account_id', 'reference', 'business_date', 'recorded_by', 'reversal_of_id', 'note',
])]
class CashMovement extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    public const UPDATED_AT = null;

    /** Money handed over by a collector, into a till. */
    public const string KIND_REMITTANCE_CASH = 'remittance_cash';

    /** A mobile-money collection ticked off the wallet statement. */
    public const string KIND_REMITTANCE_WALLET = 'remittance_wallet';

    public const string KIND_DEPOSIT = 'deposit';

    public const string KIND_TRANSFER = 'transfer';

    public const string KIND_VOUCHER_PAYOUT = 'voucher_payout';

    public const string KIND_ADVANCE_RETURN = 'advance_return';

    public const string KIND_SHORTAGE_REPAYMENT = 'shortage_repayment';

    /** The difference a day close found between the count and the book. */
    public const string KIND_CLOSE_ADJUSTMENT = 'close_adjustment';

    public const string KIND_OPENING_BALANCE = 'opening_balance';

    /**
     * Collected technician motivation fees paid to the technician
     * (docs/plans/installation-and-promotions.md, D13) — pass-through cash,
     * never an expense. Source: App\Models\TechnicianFeePayout.
     */
    public const string KIND_TECHNICIAN_FEE_PAYOUT = 'technician_fee_payout';

    /**
     * Money that came back on a paid bon that was corrected
     * (docs/plans/expenditures-and-spending-controls.md, item 9). Source:
     * the App\Models\Voucher.
     */
    public const string KIND_BON_CORRECTION = 'bon_correction';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'business_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash movements are append-only — record a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Cash movements are append-only — record a reversal instead.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'counterpart_account_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    // Cross-schema — User is pinned to the central connection.
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Its branch comes from its account (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'account';
    }
}
