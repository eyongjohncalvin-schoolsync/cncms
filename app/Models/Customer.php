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
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * `status_changed_at`/`prepaid_paused` back the prepaid-time preservation
 * feature — see .claude/skills/cncms-context/references/prepaid-pause-handling.md
 * and App\Services\CustomerStatusService, the only writer of either column.
 */
#[Fillable(['zone_id', 'name', 'location', 'bill', 'others', 'phone', 'description', 'level', 'status', 'status_reason', 'status_note', 'status_changed_at', 'prepaid_paused'])]
#[RouteKey('uuid')]
class Customer extends Model
{
    use Auditable, HasUuid, ScopesRouteBindingToBranch;

    protected function casts(): array
    {
        return [
            'bill' => 'decimal:2',
            'others' => 'decimal:2',
            'status_changed_at' => 'datetime',
            'prepaid_paused' => 'boolean',
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

    /**
     * The customer's manuscript for their most recent billing *period*
     * (`period` is a 'YYYY-MM' string, so MAX on it sorts chronologically
     * the same way App\Console\Commands\ManuscriptCalculate's own
     * previous-manuscript resolution does) — deliberately NOT
     * latestOfMany()'s default `created_at` ordering. In real data,
     * `created_at` does not track `period`: manuscript rows can be
     * touched/backfilled out of calendar order (corrections, reruns,
     * batch jobs), so the row with the newest `created_at` is not
     * reliably the row for the newest period. Ordering by `created_at`
     * here previously caused every active customer's "latest" manuscript
     * to silently resolve to a stale period, hiding real arrears growth
     * from anything that reads this relation (e.g.
     * CustomerEligibilityService's disconnection-eligibility scan).
     */
    public function latestManuscript(): HasOne
    {
        return $this->hasOne(Manuscript::class)->latestOfMany('period');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
