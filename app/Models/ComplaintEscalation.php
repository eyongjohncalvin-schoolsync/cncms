<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The escalation engine's log table (references/complaint-desk.md section
 * 3) — see the create_complaint_escalations_table migration's class doc for
 * the full "why one row per notified target, not per level" reasoning and
 * the idempotency contract. Written exclusively by
 * App\Services\ComplaintEscalationService.
 */
#[Fillable(['complaint_id', 'level', 'notified_scope', 'notified_role', 'notified_user_id', 'escalated_at'])]
#[RouteKey('uuid')]
class ComplaintEscalation extends Model
{
    use HasUuid;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'escalated_at' => 'datetime',
        ];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    /**
     * Cross-schema relation — User is pinned to the central `pgsql`
     * connection, so this resolves correctly regardless of which tenant
     * schema is currently active. Only meaningful when
     * notified_scope = 'user'.
     */
    public function notifiedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_user_id');
    }
}
