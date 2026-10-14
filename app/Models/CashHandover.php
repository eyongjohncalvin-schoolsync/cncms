<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash passing from one person to another. The giver starts it and shows
 * the 6-digit code; the receiver confirms what they actually counted. A
 * handover that arrives short moves only what was received — the rest stays
 * on the giver's till and shows up at their close.
 */
#[Fillable([
    'from_account_id', 'to_account_id', 'amount', 'received_amount', 'denominations', 'status', 'code', 'note',
    'initiated_by', 'initiated_at', 'confirmed_by', 'confirmed_at', 'local_uuid',
])]
#[Hidden(['code'])]
#[RouteKey('uuid')]
class CashHandover extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_CONFIRMED = 'confirmed';

    public const string STATUS_SHORT = 'short';

    public const string STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'denominations' => 'array',
            'initiated_at' => 'datetime',
            'confirmed_at' => 'datetime',
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

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
