<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user state for one notification — lazily materialized (a row exists
 * only once the user has read or acknowledged the notification it belongs
 * to, never written eagerly at broadcast time). Never route-bound directly
 * (no UUID/RouteKey — always addressed through its parent notification),
 * so this stays a plain internal-id model.
 *
 * `read_at` and `acknowledged_at` are genuinely independent — see
 * App\Models\Notification's class doc and in-app-notifications.md section
 * 5.
 */
#[Fillable(['notification_id', 'user_id', 'read_at', 'acknowledged_at'])]
class NotificationRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    /**
     * Cross-schema relation — User is pinned to the central `pgsql`
     * connection, so this resolves correctly regardless of which tenant
     * schema is currently active.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
