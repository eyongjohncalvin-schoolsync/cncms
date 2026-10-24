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
 * One serialised unit (a decoder). It is in exactly one place, with a status
 * that only movements change: in_store → with_technician → installed → …
 */
#[Fillable(['item_id', 'serial_no', 'status', 'location_id', 'customer_id', 'unit_cost', 'status_changed_at'])]
#[RouteKey('uuid')]
class SerialUnit extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'status_changed_at' => 'datetime',
        ];
    }

    public const array STATUSES = ['in_store', 'with_technician', 'installed', 'faulty', 'returned_to_supplier', 'lost'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
