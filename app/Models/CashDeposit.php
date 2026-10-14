<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash taken to the bank (or put on a mobile-money account), with the slip
 * photo. Posted when made; a second person confirms it against the bank
 * statement, or rejects it (which reverses it).
 */
#[Fillable([
    'from_account_id', 'to_account_id', 'amount', 'reference', 'slip_path', 'slip_sha256', 'status',
    'deposited_by', 'decided_by', 'decided_at', 'decision_note',
])]
#[RouteKey('uuid')]
class CashDeposit extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_CONFIRMED = 'confirmed';

    public const string STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'to_account_id');
    }

    public function depositor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deposited_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
