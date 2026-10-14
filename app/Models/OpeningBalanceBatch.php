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
use Illuminate\Support\Carbon;

/**
 * A set of starting balances for customers whose history only exists on
 * paper (App\Services\OpeningBalanceService). draft → pending_approval →
 * approved; a rejection returns it to draft with the reason kept.
 * Approval writes each customer's first manuscript for baselinePeriod().
 *
 * @property string $go_live_period
 * @property string $status
 */
#[Fillable([
    'go_live_period', 'status', 'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
    'rejected_by', 'rejected_at', 'rejection_reason',
])]
#[RouteKey('uuid')]
class OpeningBalanceBatch extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(OpeningBalanceEntry::class)->chaperone('batch');
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    // Cross-schema relations — User is pinned to the central connection
    // (same pattern as ArrearsAdjustment::requestedBy()).
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * The month whose manuscripts approval writes — the one before the
     * first month CNCMS bills, so the first manuscript:calculate for
     * go_live_period carries these balances forward.
     */
    public function baselinePeriod(): string
    {
        return Carbon::createFromFormat('!Y-m', $this->go_live_period)->subMonthNoOverflow()->format('Y-m');
    }
}
