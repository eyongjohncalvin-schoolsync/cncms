<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of money in or out of a cash account. Append-only (database
 * triggers refuse updates and deletes) and hash-chained — see
 * App\Services\CashLedgerService. Not Auditable: the ledger IS the audit
 * trail, and it can never change.
 */
#[Fillable([
    'uuid', 'account_id', 'direction', 'amount', 'type', 'source_type', 'source_id', 'occurred_at', 'recorded_at',
    'recorded_by', 'reverses_id', 'period', 'note', 'prev_hash', 'hash',
])]
class CashLedgerEntry extends Model
{
    use HasUuid;

    public const string IN = 'in';

    public const string OUT = 'out';

    public const string TYPE_COLLECTION = 'collection';

    public const string TYPE_EXPENSE = 'expense';

    public const string TYPE_HANDOVER_OUT = 'handover_out';

    public const string TYPE_HANDOVER_IN = 'handover_in';

    public const string TYPE_DEPOSIT_OUT = 'deposit_out';

    public const string TYPE_DEPOSIT_IN = 'deposit_in';

    public const string TYPE_CLOSE_SHORT = 'close_short';

    public const string TYPE_CLOSE_OVER = 'close_over';

    public const string TYPE_OPENING = 'opening';

    public const string TYPE_REVERSAL = 'reversal';

    public const array TYPE_LABELS = [
        self::TYPE_COLLECTION => 'Payment collected',
        self::TYPE_EXPENSE => 'Expense paid',
        self::TYPE_HANDOVER_OUT => 'Handed over',
        self::TYPE_HANDOVER_IN => 'Received',
        self::TYPE_DEPOSIT_OUT => 'Deposited',
        self::TYPE_DEPOSIT_IN => 'Deposit received',
        self::TYPE_CLOSE_SHORT => 'Shortage at close',
        self::TYPE_CLOSE_OVER => 'Surplus at close',
        self::TYPE_OPENING => 'Opening balance',
        self::TYPE_REVERSAL => 'Reversed',
    ];

    protected $table = 'cash_ledger';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /** +amount for money in, −amount for money out. */
    public function signedAmount(): string
    {
        return $this->direction === self::IN ? (string) $this->amount : '-'.$this->amount;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'account_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }
}
