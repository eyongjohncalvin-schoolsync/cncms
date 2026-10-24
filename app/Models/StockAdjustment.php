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
 * A write-off or correction (damaged, lost, theft, found…). It only posts
 * once someone other than the requester approves it.
 */
#[Fillable(['location_id', 'item_id', 'serial_unit_id', 'direction', 'qty', 'reason', 'note', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'])]
#[RouteKey('uuid')]
class StockAdjustment extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    public const array REASONS = ['damaged', 'lost', 'theft', 'found', 'faulty', 'correction'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }

    public function serialUnit(): BelongsTo
    {
        return $this->belongsTo(SerialUnit::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
