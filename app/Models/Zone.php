<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'town'])]
#[RouteKey('uuid')]
class Zone extends Model
{
    use HasUuid;

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
