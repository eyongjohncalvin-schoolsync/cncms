<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's starting position inside an OpeningBalanceBatch: what they
 * owe before the go-live month, and any advance — as money (credit) and/or
 * as whole prepaid months (locked at the customer's current bill, exactly
 * like a `months` payment's draw-down).
 *
 * Not Auditable on purpose: the on-screen ledger autosaves cell by cell, so
 * a row-level audit trail would be noise. The batch itself is audited, and
 * approval writes Auditable manuscripts carrying the final figures.
 */
#[Fillable(['opening_balance_batch_id', 'customer_id', 'arrears', 'advance_amount', 'advance_months', 'free_months', 'note'])]
class OpeningBalanceEntry extends Model
{
    use BelongsToBranch;

    protected function casts(): array
    {
        return [
            'arrears' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'advance_months' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceBatch::class, 'opening_balance_batch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Its branch comes from its customer (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'customer';
    }
}
