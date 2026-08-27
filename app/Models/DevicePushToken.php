<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Expo push registration per (user, device) — see the
 * create_device_push_tokens_table migration's doc comment for the full
 * schema rationale, and App\Repositories\Eloquent\PushTokenRepository for
 * how the audience of a push is resolved from this table.
 *
 * @property int $user_id
 * @property string $device_id
 * @property string $expo_push_token
 * @property string $platform
 * @property bool $is_valid
 */
#[Fillable(['user_id', 'device_id', 'expo_push_token', 'platform', 'is_valid', 'registered_at', 'last_used_at'])]
#[RouteKey('uuid')]
class DevicePushToken extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'is_valid' => 'boolean',
            'registered_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Cross-schema relation — User is pinned to the central `pgsql`
     * connection (see App\Models\Notification::recipientUser() for the same
     * pattern).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(PushTicket::class);
    }
}
