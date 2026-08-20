<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['command', 'period', 'ran_at', 'metadata'])]
#[RouteKey('uuid')]
class CommandRun extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
