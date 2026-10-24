<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item on a stocktake.
 */
#[Fillable(['count_id', 'item_id', 'expected_qty', 'counted_qty', 'serials'])]
class StockCountLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'expected_qty' => 'decimal:2',
            'counted_qty' => 'decimal:2',
            'serials' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }
}
