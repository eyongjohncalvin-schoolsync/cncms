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

/**
 * Money that left the company (docs/plans/expenditures-and-spending-controls.md).
 * Since E1 every new row is written by App\Services\VoucherService when a
 * bon is paid or settled (`voucher`), or as the negative `adjustment` of a
 * corrected bon; rows recorded directly before that are `legacy`. Nothing is
 * ever hard-deleted — a void keeps the row and drops it from every total.
 */
#[Fillable([
    'category_id', 'user_id', 'amount', 'description', 'receipt_path', 'spent_at',
    'notes', 'recorded_offline', 'recorded_by_device', 'local_uuid',
    'voucher_id', 'source', 'status', 'relates_to_period', 'corrects_expenditure_id',
    'edit_reason', 'void_reason', 'voided_by', 'voided_at', 'branch_id',
])]
#[RouteKey('uuid')]
class Expenditure extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    public const string SOURCE_VOUCHER = 'voucher';

    public const string SOURCE_LEGACY = 'legacy';

    public const string SOURCE_ADJUSTMENT = 'adjustment';

    public const array SOURCES = [self::SOURCE_VOUCHER, self::SOURCE_LEGACY, self::SOURCE_ADJUSTMENT];

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_VOID = 'void';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_at' => 'date',
            'recorded_offline' => 'boolean',
            'voided_at' => 'datetime',
        ];
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

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_expenditure_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Rows that count toward totals — everything not voided.
     *
     * @param  Builder<Expenditure>  $query
     * @return Builder<Expenditure>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_ACTIVE);
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /** The `Y-m` month this row counts in (its spent_at). */
    public function period(): string
    {
        return $this->spent_at->format('Y-m');
    }

    /** Its branch comes from its voucher (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'voucher';
    }
}
