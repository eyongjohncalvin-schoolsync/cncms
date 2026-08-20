<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'last_activity', 'lockout_token', 'ip_address', 'device_id'])]
class UserActivityLog extends Model
{
    protected function casts(): array
    {
        return [
            'last_activity' => 'datetime',
        ];
    }

    // Cross-schema relation — User is pinned to the central `pgsql` connection
    // via #[Connection('pgsql')], so this resolves correctly regardless of
    // which tenant schema is currently active.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
