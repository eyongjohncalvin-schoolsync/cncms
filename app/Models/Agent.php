<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'zone_id', 'user_id', 'name', 'location', 'phone', 'salary', 'email', 'dob',
    'marital_status', 'children', 'status', 'picture', 'sync_token', 'last_sync_at',
])]
#[RouteKey('uuid')]
class Agent extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'dob' => 'date',
            'children' => 'integer',
            'last_sync_at' => 'datetime',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    // Cross-schema relation — User is pinned to the central `pgsql` connection
    // via #[Connection('pgsql')], so this resolves correctly regardless of
    // which tenant schema is currently active.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
