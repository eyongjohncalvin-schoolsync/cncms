<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of an item a job of a given type normally uses.
 */
#[Fillable(['job_type', 'item_id', 'expected_qty', 'tolerance_pct'])]
class StockNorm extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'expected_qty' => 'decimal:2',
            'tolerance_pct' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }
}
