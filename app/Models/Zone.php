<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopesRouteBindingToBranch;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'name', 'town'])]
#[RouteKey('uuid')]
class Zone extends Model
{
    use Auditable, HasUuid, ScopesRouteBindingToBranch;

    /**
     * Zone carries branch_id directly — no relation hop needed.
     */
    protected static function branchRouteBindingRelation(): ?string
    {
        return null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
