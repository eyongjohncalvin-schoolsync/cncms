<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'tenant_id', 'role', 'job_title', 'branch_id', 'can_record_payments',
    'is_investor', 'investor_granted_by', 'investor_granted_at',
])]
class TenantUser extends Model
{
    // Role/branch/can_record_payments/is_investor changes on this
    // membership row are now audit-logged like Agent/Customer/Payment/
    // Zone/... — a confirmed gap the migration-strategy review flagged
    // (this model previously had no `use Auditable;` at all), and more
    // important now that this file carries sensitive grant columns
    // (can_record_payments, is_investor) alongside `role`. HasUuid is a
    // prerequisite for that: AuditableObserver requires a `uuid` attribute
    // (see the add_uuid_to_tenant_users_table migration's doc comment) —
    // this is audit-identity only, NOT the route-binding key, so every
    // existing `{tenantUser}` route/controller/frontend call keeps
    // addressing rows by plain `id`, unchanged.
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'can_record_payments' => 'boolean',
            'is_investor' => 'boolean',
            'investor_granted_at' => 'datetime',
        ];
    }

    /**
     * Keeps the central TenantUserIndex table in sync whenever a
     * tenant_users row changes in ANY tenant schema, so
     * ResolveTenant/ResolveTenantWeb can resolve a user's tenant without
     * hard-coding one or scanning every schema. Registered directly here
     * (not via static::observe(), which re-enters bootIfNotBooted() and
     * throws — see App\Traits\Auditable's docblock for the same gotcha and
     * fix) — created()/updated()/deleted() listeners only push a
     * dispatcher entry, no model instantiation.
     */
    protected static function booted(): void
    {
        static::created(fn (self $tenantUser) => $tenantUser->syncIndex());
        static::updated(fn (self $tenantUser) => $tenantUser->syncIndex());
        static::deleted(fn (self $tenantUser) => TenantUserIndex::query()
            ->where('user_id', $tenantUser->user_id)
            ->where('tenant_id', $tenantUser->tenant_id)
            ->delete());
    }

    private function syncIndex(): void
    {
        TenantUserIndex::query()->updateOrCreate(
            ['user_id' => $this->user_id, 'tenant_id' => $this->tenant_id],
            ['role' => $this->role],
        );
    }

    // Cross-schema relations — User and Tenant both pin themselves to the
    // central `pgsql` connection, so these resolve correctly regardless of
    // which tenant schema is currently active.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    // Branch is an ordinary tenant-schema table (not cross-schema like
    // User/Tenant above), so this resolves on the same `tenant` connection
    // as this model itself.
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
