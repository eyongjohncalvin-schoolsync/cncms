<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item used (or recovered) on a job.
 */
#[Fillable(['job_id', 'item_id', 'qty', 'serial_unit_id', 'direction'])]
class StockJobLine extends Model
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
