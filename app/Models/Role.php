<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\Permission;
use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A tenant role and the set of permissions it grants (RBAC v2 — see
 * docs/plans/rbac-v2-configurable-roles.md).
 *
 * Dual-key like the other tenant models: `id` for FKs / internal joins,
 * `uuid` for the route binding the Wave 3 "Users Control Center" matrix UI
 * will use (#[RouteKey('uuid')]). `tenant_users.role` still points at
 * `name`, not id/uuid — that column stays a plain string (see the create
 * migration's docblock).
 *
 * The hot path (every authenticated request) does NOT load this model — it
 * runs one lean join in App\Support\TenantContext::resolve(). This model is
 * for the management UI, the seeder, and tests.
 */
#[Fillable(['name', 'label', 'description', 'is_system', 'is_super'])]
#[RouteKey('uuid')]
class Role extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_super' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Role names are the identity `tenant_users.role` points at and are
        // matched verbatim there — normalise on the way in so 'Admin' and
        // 'admin' can never become two different roles.
        static::saving(function (self $role): void {
            if ($role->isDirty('name') && is_string($role->name)) {
                $role->name = Str::lower(trim($role->name));
            }
        });
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * The permission strings this role grants. `is_super` short-circuits to
     * the wildcard the frontend share also uses — its stored rows (there
     * are none, by seed design) are irrelevant.
     *
     * @return list<string>
     */
    public function permissionValues(): array
    {
        if ($this->is_super) {
            return ['*'];
        }

        return $this->permissions->pluck('permission')->all();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->is_super) {
            return true;
        }

        return $this->permissions->contains('permission', $permission);
    }

    /**
     * Replace this role's permission set with exactly $permissions (any
     * value not in the App\Auth\Permission catalog is dropped — the
     * catalog is the simplicity guard). Used by the Wave 3 matrix UI and
     * the default seed.
     *
     * @param  iterable<string>  $permissions
     */
    public function syncPermissions(iterable $permissions): void
    {
        $valid = array_values(array_unique(array_intersect(
            array_map(strval(...), [...$permissions]),
            Permission::values(),
        )));

        $this->permissions()->whereNotIn('permission', $valid)->delete();

        $existing = $this->permissions()->pluck('permission')->all();

        $this->permissions()->insertOrIgnore(array_map(
            fn (string $permission): array => ['role_id' => $this->id, 'permission' => $permission],
            array_values(array_diff($valid, $existing)),
        ));

        $this->unsetRelation('permissions');
    }

    /** @param  Builder<Role>  $query */
    public function scopeSystem(Builder $query): void
    {
        $query->where('is_system', true);
    }

    /** @param  Builder<Role>  $query */
    public function scopeCustom(Builder $query): void
    {
        $query->where('is_system', false);
    }
}
