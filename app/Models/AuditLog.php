<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only: the `audit_logs` table has a Postgres RULE that no-ops DELETE
// statements, and there is no `updated_at` column — records are write-once.
#[Fillable([
    'tenant_id', 'table_name', 'record_uuid', 'record_id', 'action',
    'old_values', 'new_values', 'user_id', 'impersonated_by', 'ip_address', 'user_agent', 'device_id', 'branch_id',
])]
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // Cross-schema relation — User is pinned to the central `pgsql` connection
    // via #[Connection('pgsql')], so this resolves correctly regardless of
    // which tenant schema is currently active.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The landlord who made this change while impersonating `user`
     * (App\Support\Impersonation); null on ordinary rows, which then cost
     * no query.
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_by');
    }
}
