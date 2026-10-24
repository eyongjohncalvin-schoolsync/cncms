<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Scopes\BranchScope;
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
    use Auditable, HasUuid;

    /** A branch-fenced user only ever sees their own branch's zones (branches v2 §5). */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Where the zone is: its branch's name (a branch is a town, branches v2).
     * From the per-request branch memo — no query per zone.
     */
    public function branchName(): ?string
    {
        return $this->branch_id !== null ? Branch::allMemoized()->get($this->branch_id)?->name : null;
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
