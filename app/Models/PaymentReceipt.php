<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use App\Support\ReceiptSecurityCode;
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
    // Cash controls wave 2 — see SOURCES.
    'source', 'security_code', 'receipt_leaf_id', 'field_device_id',
])]
#[RouteKey('uuid')]
class PaymentReceipt extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    public const string STATUS_ISSUED = 'issued';

    public const string STATUS_VOID = 'void';

    /**
     * Where the receipt was produced (docs/plans/cash-controls.md):
     * `office` — the server issued it (verify / on-the-spot payment);
     * `digital` — a collector's registered phone issued it in the field,
     * signed with that device's secret; `book` — a leaf of a system-printed
     * receipt book, filled in by hand.
     */
    public const string SOURCE_OFFICE = 'office';

    public const string SOURCE_DIGITAL = 'digital';

    public const string SOURCE_BOOK = 'book';

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

    public function leaf(): BelongsTo
    {
        return $this->belongsTo(ReceiptLeaf::class, 'receipt_leaf_id');
    }

    public function fieldDevice(): BelongsTo
    {
        return $this->belongsTo(FieldDevice::class);
    }

    /** The code as printed — "K7QF-2MXA" — or null for a book leaf. */
    public function formattedSecurityCode(): ?string
    {
        return $this->security_code ? ReceiptSecurityCode::format($this->security_code) : null;
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

    /** Its branch comes from its payment (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'payment';
    }
}
