<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One approver's decision on a voucher — who, what, when, and why.
 * One row per (voucher, approver): nobody decides twice.
 */
#[Fillable(['voucher_id', 'user_id', 'decision', 'note', 'decided_at'])]
class VoucherApproval extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
