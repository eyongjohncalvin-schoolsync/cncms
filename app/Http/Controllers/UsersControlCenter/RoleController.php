<?php

declare(strict_types=1);

namespace App\Http\Controllers\UsersControlCenter;

use App\Auth\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Role;
use App\Models\TenantUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users Control Center — Roles & Permissions tab (RBAC v2 Wave 3,
 * docs/plans/rbac-v2-configurable-roles.md). The role→permission matrix:
 * add / rename / delete custom roles and toggle which catalog permissions
 * each role grants.
 *
 * Everything here is gated to `roles.manage` (App\Policies\RolePolicy,
 * seeded to super + admin). Structural rules — the `is_super` row is
 * read-only, `is_system` rows can't be deleted, a role's `name` is
 * immutable — live in the policy + the FormRequests, not scattered here.
 *
 * The permission catalog (App\Auth\Permission) is closed: an update may
 * only toggle values that exist in it (UpdateRoleRequest rejects anything
 * else with a 422). That closed set IS the simplicity guard the plan
 * requires.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->with('permissions')
            ->orderByDesc('is_super')
            ->orderByDesc('is_system')
            ->orderBy('label')
            ->get();

        // One grouped count query instead of N per-role counts.
        $counts = TenantUser::query()
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        return Inertia::render('UsersControlCenter/Roles', [
            'roles' => $roles->map(fn (Role $role): array => [
                'uuid' => $role->uuid,
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'is_super' => $role->is_super,
                'permissions' => $role->permissions->pluck('permission')->values(),
                'user_count' => (int) ($counts[$role->name] ?? 0),
            ])->values(),
            // Grid structure: area heading => the permissions in it, each
            // with a human label derived from the dotted string.
            'permissionsByArea' => collect(Permission::byArea())
                ->map(fn (array $permissions): array => array_map(fn (Permission $p): array => [
                    'value' => $p->value,
                    'label' => $this->permissionLabel($p),
                ], $permissions))
                ->all(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $role = Role::query()->create([
            'name' => $request->validated('name'),
            'label' => $request->validated('label'),
            'description' => $request->validated('description'),
            'is_system' => false,
            'is_super' => false,
        ]);

        if ($source = $request->cloneSource()) {
            $role->syncPermissions($source->permissionValues());
        }

        return redirect()->route('users.roles.index')->with('success', "Role “{$role->label}” created.");
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $role->fill($request->safe()->only(['label', 'description']));
        $role->save();

        // A custom or system role's matrix. `syncPermissions` intersects
        // against the catalog itself as a second layer of defence — the
        // request already 422'd anything unknown.
        if ($request->has('permissions')) {
            $role->syncPermissions($request->validated('permissions', []));
        }

        return redirect()->route('users.roles.index')->with('success', "Role “{$role->label}” updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $holders = TenantUser::query()
            ->where('role', $role->name)
            ->with('user')
            ->get();

        if ($holders->isNotEmpty()) {
            $names = $holders
                ->map(fn (TenantUser $tu): string => $tu->user?->name ?? $tu->user?->email ?? "user #{$tu->user_id}")
                ->implode(', ');

            throw ValidationException::withMessages([
                'role' => ["Reassign these members first — still held by: {$names}."],
            ]);
        }

        $label = $role->label;
        // role_permissions rows cascade on the FK.
        $role->delete();

        return redirect()->route('users.roles.index')->with('success', "Role “{$label}” deleted.");
    }

    /**
     * "customers.change_status" => "Change status". The area prefix is
     * already the section heading, so drop it and humanise the rest.
     */
    private function permissionLabel(Permission $permission): string
    {
        $rest = explode('.', $permission->value, 2)[1] ?? $permission->value;

        return Str::ucfirst(str_replace('_', ' ', $rest));
    }
}
