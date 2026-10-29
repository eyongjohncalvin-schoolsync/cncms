<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Money missing, owned by a named person until someone else settles it
 * (owner, 2026-10-28, wave 3): a till's day-close count, a handover or
 * branch transfer received short, a refused bank deposit, a safe count.
 * Written only by App\Services\ShortageService; never deleted — a reopened
 * day or a later count adds a row, it never rewrites one.
 */
#[Fillable([
    'branch_id', 'owner_user_id', 'kind', 'amount', 'source_type', 'source_id', 'note',
    'status', 'settled_by', 'settled_at', 'settled_note', 'created_by',
])]
#[RouteKey('uuid')]
class CashShortage extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    public const string KIND_TILL_CLOSE = 'till_close';

    public const string KIND_HANDOVER = 'handover';

    public const string KIND_DEPOSIT = 'deposit';

    public const string KIND_BRANCH_TRANSFER = 'branch_transfer';

    public const string KIND_SAFE_COUNT = 'safe_count';

    public const string KIND_ADVANCE = 'advance';

    public const array KIND_LABELS = [
        self::KIND_TILL_CLOSE => 'Till day close',
        self::KIND_HANDOVER => 'Handed over short',
        self::KIND_DEPOSIT => 'Deposit not in the bank',
        self::KIND_BRANCH_TRANSFER => 'Branch transfer received short',
        self::KIND_SAFE_COUNT => 'Safe count',
        self::KIND_ADVANCE => 'Advance not accounted for',
    ];

    public const string STATUS_OPEN = 'open';

    public const string STATUS_REPAID = 'repaid';

    public const string STATUS_WRITTEN_OFF = 'written_off';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    // Cross-schema — User is pinned to the central connection.
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /** Set by ShortageService (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return null;
    }
}
