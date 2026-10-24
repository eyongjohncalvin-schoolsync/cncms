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
 * A contiguous range of receipt numbers handed to one person
 * (App\Services\ReceiptStockService): `digital` — the holder's registered
 * phone issues them, signing each with its device secret — or `book` — a
 * printed book of numbered leaves, each with its own QR. The numbers come
 * from the same RCP-YYYY-NNNNNN series office receipts use, so a number is
 * unique across every kind of receipt.
 */
#[Fillable([
    'number', 'kind', 'holder_user_id', 'branch_id', 'field_device_id', 'year', 'first_sequence', 'last_sequence',
    'status', 'allocated_by', 'closed_at', 'closed_by',
    'pdf_status', 'pdf_path', 'pdf_disk', 'pdf_error', 'pdf_generated_at',
])]
#[RouteKey('uuid')]
class ReceiptBlock extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    public const string KIND_DIGITAL = 'digital';

    public const string KIND_BOOK = 'book';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'first_sequence' => 'integer',
            'last_sequence' => 'integer',
            'closed_at' => 'datetime',
            'pdf_generated_at' => 'datetime',
        ];
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(ReceiptLeaf::class)->orderBy('number');
    }

    public function fieldDevice(): BelongsTo
    {
        return $this->belongsTo(FieldDevice::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function size(): int
    {
        return $this->last_sequence - $this->first_sequence + 1;
    }

    public function firstNumber(): string
    {
        return ReceiptLeaf::formatNumber($this->year, $this->first_sequence);
    }

    public function lastNumber(): string
    {
        return ReceiptLeaf::formatNumber($this->year, $this->last_sequence);
    }

    /** Set by the creating service (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return null;
    }
}
