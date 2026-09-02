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
 * The receipt the business issues to a customer for a recorded payment —
 * see docs/plans/payment-receipts-and-whatsapp.md and
 * App\Services\PaymentReceiptService (the only writer).
 *
 * Distinct from PaymentVerification::receipt_photo_path (proof-of-payment
 * evidence uploaded during verification). Auto-issued on verify(), voided —
 * never deleted — on a later rejection.
 *
 * `snapshot` is the frozen source of truth for the PDF: everything printed
 * on the receipt is read from it, never from the live customer/company/
 * payment rows, so an edit or manuscript recalc after issue can never
 * change an issued receipt.
 */
#[Fillable([
    'payment_id', 'receipt_number', 'issued_at', 'issued_by', 'amount',
    'pdf_path', 'pdf_disk', 'snapshot', 'sent_log', 'status',
])]
#[RouteKey('uuid')]
class PaymentReceipt extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_ISSUED = 'issued';

    public const string STATUS_VOID = 'void';

    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'amount' => 'decimal:2',
            'snapshot' => 'array',
            'sent_log' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Who generated the receipt — a central `users` row (User pins itself to
     * the `pgsql` connection, so this cross-schema belongsTo resolves
     * regardless of the active tenant schema, same as Customer::archivedBy()).
     * Null when the system auto-issued it on verify() with no acting user
     * threaded through (e.g. the backfill command, or a queued verify).
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /** @param  Builder<PaymentReceipt>  $query */
    public function scopeIssued(Builder $query): void
    {
        $query->where('status', self::STATUS_ISSUED);
    }

    /** @param  Builder<PaymentReceipt>  $query */
    public function scopeVoid(Builder $query): void
    {
        $query->where('status', self::STATUS_VOID);
    }
}
