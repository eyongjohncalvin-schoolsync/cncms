<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a stock transfer.
 */
#[Fillable(['transfer_id', 'item_id', 'qty', 'serial_unit_id'])]
class StockTransferLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }

    public function serialUnit(): BelongsTo
    {
        return $this->belongsTo(SerialUnit::class);
    }
}
