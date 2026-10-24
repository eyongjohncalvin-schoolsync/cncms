<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Scopes\BranchScope;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One receipt number inside a ReceiptBlock. Every number must end `used`
 * (its PaymentReceipt points back here) or `cancelled` with a reason; one
 * still `unused` in a closed block, or scanned by a customer while unused
 * (`scanned_unused_at`), is a cash-control alert.
 *
 * Leaves are bulk-inserted when the block is allocated (no model events), so
 * the audit trail records each leaf's use / cancellation, not its creation.
 */
#[Fillable(['receipt_block_id', 'number', 'status', 'used_at', 'cancelled_reason', 'cancelled_by', 'cancelled_at', 'scanned_unused_at'])]
class ReceiptLeaf extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_UNUSED = 'unused';

    public const string STATUS_USED = 'used';

    public const string STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'scanned_unused_at' => 'datetime',
        ];
    }

    /** Same shape as PaymentReceiptService::allocateNumber(). */
    public static function formatNumber(int $year, int $sequence): string
    {
        return sprintf('RCP-%04d-%06d', $year, $sequence);
    }

    public function block(): BelongsTo
    {
        // A leaf always knows its book, whoever looks it up (branches v2) — refusals then name the real holder.
        return $this->belongsTo(ReceiptBlock::class, 'receipt_block_id')->withoutGlobalScope(BranchScope::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isUnused(): bool
    {
        return $this->status === self::STATUS_UNUSED;
    }
}
