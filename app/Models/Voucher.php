<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A voucher / bon de caisse (docs/plans/cash-controls.md, wave 5): the only
 * way money leaves the company. pending → approved → paid (→ settled for a
 * cash advance); rejected / cancelled end it. App\Services\VoucherService
 * holds the rules; `requested_by` is never mass-assigned from a request.
 */
#[Fillable([
    'number', 'kind', 'payee_name', 'purpose', 'expense_category_id', 'amount', 'needed_on', 'status',
    'approval_tier', 'approval_mode', 'tier_reasons', 'required_approver_ids', 'attachment_path',
    'requested_by', 'approved_at', 'rejected_by', 'rejection_reason',
    'paid_by', 'paid_at', 'paid_from_account_id', 'payment_method', 'payment_reference', 'received_by_name', 'signed_copy_path', 'expenditure_id',
    'settle_due_on', 'spent_amount', 'returned_amount', 'settled_by', 'settled_at', 'settlement_note',
])]
#[RouteKey('uuid')]
class Voucher extends Model
{
    use Auditable, HasUuid;

    public const string KIND_EXPENSE = 'expense';

    public const string KIND_ADVANCE = 'advance';

    public const string TIER_STANDARD = 'standard';

    public const string TIER_STAKEHOLDERS = 'stakeholders';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'needed_on' => 'date',
            'tier_reasons' => 'array',
            'required_approver_ids' => 'array',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'settle_due_on' => 'date',
            'spent_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(VoucherApproval::class)->orderBy('decided_at');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function paidFrom(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'paid_from_account_id');
    }

    public function expenditure(): BelongsTo
    {
        return $this->belongsTo(Expenditure::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function needsStakeholders(): bool
    {
        return $this->approval_tier === self::TIER_STAKEHOLDERS;
    }

    /** @return list<int> */
    public function requiredApproverIds(): array
    {
        return array_map('intval', $this->required_approver_ids ?? []);
    }
}
