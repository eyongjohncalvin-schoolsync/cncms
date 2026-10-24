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
 * Something the business stocks. `serial` items (decoders, routers) are tracked
 * one by one; `bulk` items by quantity (cable in metres, connectors).
 */
#[Fillable(['sku', 'barcode', 'name', 'category', 'tracking', 'unit', 'reorder_level', 'avg_cost', 'service_id', 'is_active'])]
#[RouteKey('uuid')]
class StockItem extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:2',
            'avg_cost' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public const array CATEGORIES = ['decoder', 'router', 'cable', 'connector', 'splitter', 'amplifier', 'tool', 'other'];

    public const array UNITS = ['piece', 'metre', 'roll', 'box'];

    public function isSerial(): bool
    {
        return $this->tracking === 'serial';
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
