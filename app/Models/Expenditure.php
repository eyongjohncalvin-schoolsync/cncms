<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expense. Its life cycle (App\Services\ExpenditureService):
 *
 *   pending_evidence ─receipt─> pending_approval ─> [pending_second_approval ─>] approved
 *                                               └─> rejected
 *   anything but void ─> void (with a reason; never deleted)
 *
 * Small, clean expenses are approved automatically. Only `approved` ones
 * count in the profit and loss. Once approved or voided the money facts
 * are frozen by a database trigger (expenditures_guard_update), and rows
 * can never be deleted (expenditures_guard_delete).
 */
#[Fillable([
    'category_id', 'user_id', 'amount', 'description', 'receipt_path', 'spent_at',
    'notes', 'recorded_offline', 'recorded_by_device', 'local_uuid',
    'status', 'auto_approved', 'payment_method', 'payment_ref', 'supplier_id', 'reference_no', 'branch_id',
    'service_id', 'recurring_expense_id', 'recurring_period', 'receipt_sha256', 'receipt_meta', 'flags', 'device_recorded_at',
    'first_approved_by', 'first_approved_at', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
    'voided_by', 'voided_at', 'void_reason',
])]
#[RouteKey('uuid')]
class Expenditure extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_PENDING_EVIDENCE = 'pending_evidence';

    public const string STATUS_PENDING_APPROVAL = 'pending_approval';

    public const string STATUS_PENDING_SECOND_APPROVAL = 'pending_second_approval';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_VOID = 'void';

    public const array PENDING_STATUSES = [
        self::STATUS_PENDING_EVIDENCE,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_PENDING_SECOND_APPROVAL,
    ];

    /** Payment methods an expense can be paid by (same set as payments). */
    public const array PAYMENT_METHODS = ['cash', 'mtn_momo', 'orange_money', 'bank'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_at' => 'date',
            'recorded_offline' => 'boolean',
            'auto_approved' => 'boolean',
            'flags' => 'array',
            'receipt_meta' => 'array',
            'device_recorded_at' => 'datetime',
            'first_approved_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Expenses that count: approved ones.
     *
     * @param  Builder<self>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('expenditures.status', self::STATUS_APPROVED);
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING_STATUSES, true);
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags ?? [], true);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    // Cross-schema relation — User is pinned to the central `pgsql` connection
    // via #[Connection('pgsql')], so this resolves correctly regardless of
    // which tenant schema is currently active.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The service this cost belongs to; null = shared across services. */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class);
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approved_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
