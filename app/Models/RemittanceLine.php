<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment inside a Remittance — a payment can be handed over exactly
 * once (payment_id is unique). Frozen at submission: method, amount and
 * operator reference as they were when handed over.
 *
 * A MoMo / Orange / bank line is money the collector says went straight
 * into a company account. The cashier ticks it off that account's statement
 * at the handover (`found`); one not ticked stays owed by the collector
 * (`outcome` null) until it is found later, repaid in cash, or written off
 * (owner, 2026-10-28).
 */
#[Fillable(['remittance_id', 'payment_id', 'method', 'amount', 'reference', 'wallet_confirmed', 'outcome', 'cash_account_id', 'settled_by', 'settled_at', 'settled_note'])]
class RemittanceLine extends Model
{
    public const string OUTCOME_FOUND = 'found';

    public const string OUTCOME_REPAID = 'repaid';

    public const string OUTCOME_WRITTEN_OFF = 'written_off';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'wallet_confirmed' => 'boolean',
            'settled_at' => 'datetime',
        ];
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(Remittance::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The company account a found line was booked into. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    // Cross-schema — User is pinned to the central connection.
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function isNonCash(): bool
    {
        return in_array($this->method, Payment::NON_CASH_METHODS, true);
    }

    /** Non-cash money not seen in the company account yet — still owed by the collector. */
    public function isOwed(): bool
    {
        return $this->isNonCash() && $this->outcome === null;
    }
}
