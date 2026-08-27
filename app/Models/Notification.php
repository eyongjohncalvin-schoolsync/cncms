<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per logical in-app notification event — never duplicated per
 * recipient at write time. See App\Models\NotificationRecipient for the
 * lazily-materialized per-user read/acknowledge state, and
 * in-app-notifications.md sections 2-3 for the full reasoning.
 *
 * Write-once: no `updated_at` column (const UPDATED_AT = null below), same
 * shape as App\Models\AuditLog.
 */
#[Fillable([
    'type', 'severity', 'title', 'body', 'link', 'source_type', 'source_uuid',
    'broadcast_scope', 'recipient_user_id', 'recipient_role',
])]
#[RouteKey('uuid')]
class Notification extends Model
{
    use HasUuid;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Cross-schema relation — User is pinned to the central `pgsql`
     * connection, so this resolves correctly regardless of which tenant
     * schema is currently active. Only meaningful when
     * broadcast_scope = 'user'.
     */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class);
    }

    /**
     * Whether this notification's audience includes the given user —
     * shared by App\Policies\NotificationPolicy (a single already-loaded
     * model) and mirrored at the query level by
     * App\Repositories\Eloquent\NotificationRepository::audienceScope()
     * (many rows, can't load-and-check in PHP). Keep both in sync if this
     * logic ever changes.
     *
     * Investors are addressed via recipient_role = 'investor' rather than
     * a literal `tenant_users.role` value (there is no 'investor' role
     * enum member — see rbac-permissions.md section 7): when
     * recipient_role is 'investor', matching is driven exclusively by the
     * separate $isInvestor flag, never by $role — even if $role somehow
     * were the literal string "investor", that alone would not match.
     */
    public function matchesAudience(int $userId, string $role, bool $isInvestor): bool
    {
        return match ($this->broadcast_scope) {
            'all' => true,
            'user' => $this->recipient_user_id === $userId,
            'role' => $this->recipient_role === 'investor' ? $isInvestor : $this->recipient_role === $role,
            default => false,
        };
    }
}
