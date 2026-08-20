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
