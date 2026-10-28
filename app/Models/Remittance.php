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
 * The daily income form (docs/plans/cash-controls.md, wave 4): a collector
 * hands over the money they collected. The system fills in what they should
 * have (their not-yet-handed-over payments, split by method); the collector
 * declares the cash they hold; a cashier counts it, ticks each mobile-money
 * line off the wallet statement and receives it into their till. Any
 * shortage stays open until repaid or written off.
 */
#[Fillable([
    'number', 'collector_user_id', 'branch_id', 'business_date', 'status',
    'expected_cash', 'expected_mtn', 'expected_orange', 'expected_bank',
    'declared_cash', 'declared_denominations', 'collector_note', 'submitted_at',
    'received_by', 'received_at', 'till_account_id', 'received_cash', 'received_denominations', 'cash_variance', 'receiver_note',
    'shortage_status', 'shortage_settled_by', 'shortage_settled_at', 'shortage_note', 'bons_total',
])]
#[RouteKey('uuid')]
class Remittance extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'expected_cash' => 'decimal:2',
            'bons_total' => 'decimal:2',
            'expected_mtn' => 'decimal:2',
            'expected_orange' => 'decimal:2',
            'expected_bank' => 'decimal:2',
            'declared_cash' => 'decimal:2',
            'declared_denominations' => 'array',
            'received_cash' => 'decimal:2',
            'received_denominations' => 'array',
            'cash_variance' => 'decimal:2',
            'submitted_at' => 'datetime',
            'received_at' => 'datetime',
            'shortage_settled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RemittanceLine::class);
    }

    /**
     * Approved bons the collector already paid out of these collections
     * ("already spent from my collections") — paid by the cashier as part of
     * receiving this handover.
     */
    public function bons(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /** The cash that should actually be in the drawer: collected cash minus the bons spent from it. */
    public function cashToHandOver(): string
    {
        return bcsub((string) $this->expected_cash, (string) ($this->bons_total ?? '0'), 2);
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'till_account_id');
    }

    // Cross-schema — User is pinned to the central connection.
    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isReceived(): bool
    {
        return $this->status === 'received';
    }

    /** Set by the creating service (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return null;
    }
}
