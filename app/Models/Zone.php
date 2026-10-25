<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUpperCaseLabels;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\BranchScope;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['branch_id', 'name', 'town'])]
#[RouteKey('uuid')]
class Zone extends Model
{
    use Auditable, HasUpperCaseLabels, HasUuid;

    /** A branch-fenced user only ever sees their own branch's zones (branches v2 §5). */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /** Zone names are stored upper-case (see HasUpperCaseLabels). */
    protected function upperCaseLabels(): array
    {
        return ['name'];
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

    /** Agents whose HOME zone this is — who covers it is coverage(). */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /** @return HasMany<ZoneAgent, $this> */
    public function coverage(): HasMany
    {
        return $this->hasMany(ZoneAgent::class);
    }

    /** The zone's responsible collector — the name on its bills. */
    public function responsibleCoverage(): HasOne
    {
        return $this->hasOne(ZoneAgent::class)->where('role', ZoneAgent::ROLE_RESPONSIBLE);
    }
}
