<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A month's statement (bank, wallet) or count (safe) against the cash book
 * (owner, 2026-10-28, wave 3). A bank or wallet difference stays on the
 * alerts until the missing items are booked; a safe short is its holder's
 * shortage. One per account and month.
 */
#[Fillable(['cash_account_id', 'branch_id', 'period', 'book_balance', 'statement_balance', 'difference', 'photo_path', 'note', 'counted_by'])]
#[RouteKey('uuid')]
class CashStatementCount extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    protected function casts(): array
    {
        return [
            'book_balance' => 'decimal:2',
            'statement_balance' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    // Cross-schema — User is pinned to the central connection.
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /** Its branch comes from its account (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'account';
    }
}
