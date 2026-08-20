<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['zone_id', 'name', 'location', 'bill', 'others', 'phone', 'description', 'level', 'status'])]
#[RouteKey('uuid')]
class Customer extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'bill' => 'decimal:2',
            'others' => 'decimal:2',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    public function latestManuscript(): HasOne
    {
        return $this->hasOne(Manuscript::class)->latestOfMany();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
