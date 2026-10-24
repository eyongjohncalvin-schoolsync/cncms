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
 * Stock moving between two holders (store → technician or back). Nothing
 * moves until the receiver accepts it.
 */
#[Fillable(['from_location_id', 'to_location_id', 'status', 'note', 'created_by', 'decided_by', 'decided_at', 'decision_note', 'local_uuid'])]
#[RouteKey('uuid')]
class StockTransfer extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
