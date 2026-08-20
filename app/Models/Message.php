<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'content', 'sid', 'status', 'type'])]
#[RouteKey('uuid')]
class Message extends Model
{
    use HasUuid;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
