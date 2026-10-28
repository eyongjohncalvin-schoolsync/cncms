<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A collector asks the office to reverse one of their payments (owner,
 * 2026-10-28) — from the phone or the web, or automatically when a payment
 * cancelled on the phone had already reached the server. The office decides
 * in the Reverse dialog (done) or declines it with a note; one open request
 * per payment.
 */
#[Fillable(['payment_id', 'requested_by', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note'])]
#[RouteKey('uuid')]
class PaymentReversalRequest extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DONE = 'done';

    public const string STATUS_DECLINED = 'declined';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
