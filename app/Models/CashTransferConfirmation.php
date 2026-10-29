<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's "yes, the bank shows it" on a deposit (CashTransfer) —
 * never the depositor, never the same person twice.
 */
#[Fillable(['cash_transfer_id', 'user_id'])]
class CashTransferConfirmation extends Model
{
    public const UPDATED_AT = null;

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(CashTransfer::class, 'cash_transfer_id');
    }

    // Cross-schema — User is pinned to the central connection.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
