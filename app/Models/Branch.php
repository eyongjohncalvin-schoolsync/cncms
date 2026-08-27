<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
#[RouteKey('uuid')]
class Branch extends Model
{
    use Auditable, HasUuid;

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
