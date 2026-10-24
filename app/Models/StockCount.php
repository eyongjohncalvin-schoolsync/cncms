<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A blind stocktake of one location, never by the person who holds it.
 * Differences post only after someone else approves.
 */
#[Fillable(['location_id', 'status', 'counted_by', 'decided_by', 'submitted_at', 'decided_at', 'note'])]
#[RouteKey('uuid')]
class StockCount extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'count_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
