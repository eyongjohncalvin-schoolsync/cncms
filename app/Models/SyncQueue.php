<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'device_id', 'direction', 'entity_type', 'entity_uuid', 'local_uuid', 'payload',
    'status', 'attempt_count', 'attempted_at', 'completed_at', 'error_message',
])]
#[RouteKey('uuid')]
class SyncQueue extends Model
{
    use HasUuid;

    const UPDATED_AT = null;

    // Eloquent's default pluralization guesses "sync_queues", but the
    // migrated table (database/migrations/tenant/*_create_sync_queue_table)
    // is named "sync_queue" (singular, matching database-schema.md) — must
    // be declared explicitly or every query 500s with "relation sync_queues
    // does not exist".
    protected $table = 'sync_queue';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt_count' => 'integer',
            'attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
