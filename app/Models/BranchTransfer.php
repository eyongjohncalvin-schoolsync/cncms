<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Scopes\BranchScope;
use App\Support\TenantContext;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money one branch sends another, usually headquarters (branches v2 §4.7,
 * D6): out of the sender's account when sent, in transit until the receiving
 * branch puts it into one of its accounts (or refuses it, which returns it).
 * Never income or expense. Written only by App\Services\BranchTransferService.
 *
 * Belongs to two branches, so it is fenced explicitly (scopeVisible(), route
 * binding): a fenced user sees transfers their branch sent or receives.
 */
#[Fillable([
    'number', 'from_branch_id', 'to_branch_id', 'from_account_id', 'to_account_id', 'amount', 'status', 'note',
    'sent_by', 'sent_at', 'received_by', 'received_at', 'receive_note',
])]
#[RouteKey('uuid')]
class BranchTransfer extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_SENT = 'sent';

    public const string STATUS_RECEIVED = 'received';

    public const string STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** Transfers the current fenced user's branch sent or receives; all for anyone else. */
    public function scopeVisible(Builder $query): Builder
    {
        $branchId = TenantContext::currentBranchId();

        return $branchId === null
            ? $query
            : $query->where(fn (Builder $inner) => $inner->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId));
    }

    /** Route binding: another pair of branches' transfer is a 404 for a fenced user. */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->visible();
    }

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /** The sender's account — always resolvable, whichever branch looks (it may be the other branch's). */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'from_account_id')->withoutGlobalScope(BranchScope::class);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'to_account_id')->withoutGlobalScope(BranchScope::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
