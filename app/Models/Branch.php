<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A town the company operates in. Exactly one branch is headquarters
 * (is_headquarters, docs/plans/branches-v2.md): HQ costs, staff and accounts
 * belong to it, and it can never be deleted.
 */
#[Fillable(['name'])]
#[RouteKey('uuid')]
class Branch extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
        ];
    }

    public static function headquartersId(): ?int
    {
        $id = static::query()->where('is_headquarters', true)->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
