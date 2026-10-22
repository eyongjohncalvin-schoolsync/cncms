<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment inside a Remittance — a payment can be handed over exactly
 * once (payment_id is unique). Frozen at submission: method, amount and
 * operator reference as they were when handed over.
 */
#[Fillable(['remittance_id', 'payment_id', 'method', 'amount', 'reference', 'wallet_confirmed'])]
class RemittanceLine extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'wallet_confirmed' => 'boolean',
        ];
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(Remittance::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
