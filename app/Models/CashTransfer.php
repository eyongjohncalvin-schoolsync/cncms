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
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money on its way between two accounts of one branch, waiting for someone
 * other than the sender (owner, 2026-10-28, wave 3):
 *
 *  - handover — into another person's till (the manager's pickup from a
 *    cashier): that person accepts what they counted; less is the sender's
 *    shortage. The sender may recall it until then.
 *  - deposit  — into a bank account, with the slip number and photo:
 *    `required_confirmations` people (never the depositor) confirm it
 *    against the bank's own record; refused = the depositor's shortage.
 *
 * Out of the sender's account when sent; while `sent`, this row IS the
 * money in transit. Written only by App\Services\CashTransferService.
 */
#[Fillable([
    'number', 'kind', 'branch_id', 'from_account_id', 'to_account_id', 'to_user_id', 'amount', 'received_amount', 'status',
    'slip_number', 'slip_photo_path', 'slip_photo_hash', 'required_confirmations', 'note',
    'sent_by', 'sent_at', 'decided_by', 'decided_at', 'decision_note',
])]
#[RouteKey('uuid')]
class CashTransfer extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    public const string KIND_HANDOVER = 'handover';

    public const string KIND_DEPOSIT = 'deposit';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_RECEIVED = 'received';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_RECALLED = 'recalled';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isDeposit(): bool
    {
        return $this->kind === self::KIND_DEPOSIT;
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'to_account_id');
    }

    /** @return HasMany<CashTransferConfirmation, $this> */
    public function confirmations(): HasMany
    {
        return $this->hasMany(CashTransferConfirmation::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Set by CashTransferService (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return null;
    }
}
