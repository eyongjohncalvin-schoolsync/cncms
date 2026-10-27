<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BumpsCacheVersion;
use App\Support\CacheVersion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent covering one zone — as its `responsible` collector (one per
 * zone, printed on its bills) or a `helper`. Its branch is its zone's, and
 * the database keeps the agent in that same branch (composite foreign keys).
 * App\Services\ZoneCoverageService is the only writer.
 */
#[Fillable(['zone_id', 'agent_id', 'role', 'assigned_by'])]
class ZoneAgent extends Model
{
    use BelongsToBranch, BumpsCacheVersion;

    /** @return list<string> */
    public function cacheVersions(): array
    {
        return [CacheVersion::AGENTS];
    }

    public const string ROLE_RESPONSIBLE = 'responsible';

    public const string ROLE_HELPER = 'helper';

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function isResponsible(): bool
    {
        return $this->role === self::ROLE_RESPONSIBLE;
    }

    protected function branchParent(): ?string
    {
        return 'zone';
    }
}
