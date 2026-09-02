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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `status_changed_at`/`prepaid_paused` back the prepaid-time preservation
 * feature — see .claude/skills/cncms-context/references/prepaid-pause-handling.md
 * and App\Services\CustomerStatusService, the only writer of either column.
 *
 * SoftDeletes backs customer archiving (customer-deletion deliberation,
 * 2026-08-29) — the first and only soft-deleting model in the app. A
 * customer with billing history is archived, never hard-deleted; the
 * SoftDeletes global scope drops archived customers from every ordinary
 * Customer::query() (lists, manuscript runs, eligibility scans, the
 * dashboard) while their payment/manuscript history stays physically
 * intact. `archived_by`/`archived_reason` are set only by
 * App\Services\CustomerService::archive()/restore() — deliberately NOT in
 * Fillable, so a customer edit form can never touch them.
 */
#[Fillable(['zone_id', 'name', 'location', 'bill', 'others', 'phone', 'description', 'level', 'status', 'status_reason', 'status_note', 'status_changed_at', 'prepaid_paused'])]
#[RouteKey('uuid')]
class Customer extends Model
{
    use Auditable, HasUuid, ScopesRouteBindingToBranch, SoftDeletes;

    protected function casts(): array
    {
        return [
            'bill' => 'decimal:2',
            'others' => 'decimal:2',
            'status_changed_at' => 'datetime',
            'prepaid_paused' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * Who archived this customer (customer-deletion deliberation) — a
     * central `users` row (User pins itself to the central `pgsql`
     * connection, so this cross-schema belongsTo resolves regardless of the
     * active tenant schema, same as TenantUser::user()). Null unless the
     * customer is currently archived.
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
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
