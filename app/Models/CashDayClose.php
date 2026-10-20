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
 * A till's end-of-day count (docs/plans/cash-controls.md, wave 4): what the
 * cash book expects against what was counted. While closed (closed_at set)
 * the till refuses new movements for that day; reopening needs a reason.
 */
#[Fillable([
    'cash_account_id', 'business_date', 'expected', 'counted', 'denominations', 'variance', 'note',
    'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason',
])]
#[RouteKey('uuid')]
class CashDayClose extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'expected' => 'decimal:2',
            'counted' => 'decimal:2',
            'variance' => 'decimal:2',
            'denominations' => 'array',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    // Cross-schema — User is pinned to the central connection.
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
