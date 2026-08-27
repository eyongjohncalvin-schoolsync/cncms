<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Complaint Desk's core record — see references/complaint-desk.md
 * section 2 for the full field-by-field design. `submitted_by` is
 * deliberately absent from #[Fillable]: it is always set explicitly from
 * auth()->id() by App\Repositories\Eloquent\ComplaintRepository::create()
 * (same convention as Expenditure::create()'s $userId parameter), never via
 * mass assignment from request input.
 *
 * Escalation (`escalated_at`, the 48h clock, level 0-3 broadcasts) is built
 * — see App\Services\ComplaintEscalationService and
 * App\Support\ScheduledTasks\ComplaintEscalationCheckTaskType
 * (references/complaint-desk.md sections 3/5). `escalated_at` is written
 * exactly once, only by ComplaintEscalationService::fireLevel2() (the 48h
 * "Full staff emergency" threshold) — Level 0/1 firing does NOT set it, so
 * the frontend's red "Escalated" badge only ever appears once a complaint
 * is genuinely 48h overdue, not merely assigned or 24h-team-escalated.
 */
#[Fillable([
    'category', 'title', 'description', 'urgent', 'status', 'customer_id', 'zone_id',
    'assigned_to', 'resolved_at', 'resolved_by', 'resolution_notes', 'escalated_at', 'duplicate_of_id',
    // Client-generated sync idempotency key — see the
    // add_local_uuid_to_complaints_table migration and
    // App\Services\SyncService::pushComplaint(). Always null for a web
    // submission; only ever populated via the mobile sync push path.
    'local_uuid',
    // The field agent's actual offline-submission timestamp — see the
    // add_collected_at_to_complaints_table migration and
    // App\Services\SyncService::pushComplaint()'s doc comment.
    // Deliberately separate from `created_at` (server-arrival time,
    // untouched). Always null for a web submission; only ever populated
    // via the mobile sync push path.
    'collected_at',
])]
#[RouteKey('uuid')]
class Complaint extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'urgent' => 'boolean',
            'resolved_at' => 'datetime',
            'escalated_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    // Cross-schema relations — User is pinned to the central `pgsql`
    // connection via #[Connection('pgsql')], so these resolve correctly
    // regardless of which tenant schema is currently active (same pattern
    // as Expenditure::user()).
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * The original complaint this one was linked as a duplicate of (manager
     * action — see references/complaint-desk.md section 4.2). A linked
     * duplicate rides on the original's escalation clock instead of its own.
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /**
     * Complaints linked as duplicates of this one.
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }

    /**
     * The escalation engine's log rows for this complaint (references/
     * complaint-desk.md section 3) — one row per notification actually
     * sent, see ComplaintEscalation's class doc. Used to compute the
     * "armed" Level 3 state and the resolution/de-escalation notice's
     * accumulated audience (App\Services\ComplaintEscalationService).
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(ComplaintEscalation::class);
    }
}
