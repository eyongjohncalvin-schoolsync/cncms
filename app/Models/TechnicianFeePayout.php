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
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cashier paying collected technician motivation fees (D13,
 * docs/plans/installation-and-promotions.md) to a named technician: one
 * cash-book outflow from the cashier's till (CashMovement kind
 * technician_fee_payout, document TF-…), linked to the fee payments it
 * settles. Pass-through money — never an expense, never income. Written only
 * by App\Services\TechnicianFeeService.
 */
#[Fillable([
    'number', 'technician_name', 'technician_user_id', 'amount', 'cash_account_id', 'cash_movement_id', 'paid_by', 'paid_at', 'note',
])]
#[RouteKey('uuid')]
class TechnicianFeePayout extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** The fee payments this payout settled. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_user_id');
    }

    /** Its branch comes from its cashAccount (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'cashAccount';
    }
}
