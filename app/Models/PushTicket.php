<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per Expo send-ticket awaiting a receipt check — see the
 * create_push_tickets_table migration's doc comment.
 *
 * @property string $ticket_id
 * @property int $device_push_token_id
 * @property string $status
 */
#[Fillable(['ticket_id', 'device_push_token_id', 'source_notification_uuid', 'status', 'checked_at'])]
class PushTicket extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function devicePushToken(): BelongsTo
    {
        return $this->belongsTo(DevicePushToken::class);
    }
}
