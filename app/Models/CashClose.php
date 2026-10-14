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
 * A daily blind count of a till or safe: counted vs what the ledger says
 * should be there. `balanced` needs nothing; a small difference is
 * approved automatically; anything bigger waits for someone else.
 */
#[Fillable([
    'account_id', 'business_date', 'expected', 'counted', 'variance', 'denominations', 'status', 'note',
    'closed_by', 'decided_by', 'decided_at', 'decision_note',
])]
#[RouteKey('uuid')]
class CashClose extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_BALANCED = 'balanced';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'expected' => 'decimal:2',
            'counted' => 'decimal:2',
            'variance' => 'decimal:2',
            'denominations' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'account_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
