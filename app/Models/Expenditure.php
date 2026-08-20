<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category_id', 'user_id', 'amount', 'description', 'receipt_path', 'spent_at',
    'notes', 'recorded_offline', 'recorded_by_device',
])]
#[RouteKey('uuid')]
class Expenditure extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_at' => 'date',
            'recorded_offline' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    // Cross-schema relation — User is pinned to the central `pgsql` connection
    // via #[Connection('pgsql')], so this resolves correctly regardless of
    // which tenant schema is currently active.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
