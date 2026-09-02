<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Roles & Permissions matrix (RBAC v2 Wave 3 — see
 * docs/plans/rbac-v2-configurable-roles.md, "Users Control Center"). One
 * permission gates the whole surface: `roles.manage` (seeded to super +
 * admin, matching who could manage users before v2).
 *
 * Two structural rules live here rather than in the controller because
 * they are true regardless of who is asking:
 *
 *   - the `is_super` role's row can never be edited or deleted — its
 *     permission list is ignored entirely (TenantContext::isSuper() short-
 *     circuits) and it is the Gate::before bypass the whole design leans on;
 *   - `is_system` roles (the 5 seeded ones) can have their permission
 *     matrix edited but can never be deleted — `tenant_users.role` and a
 *     lot of hardcoded fixture/seed code addresses them by name.
 */
class RolePolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('roles.manage');
    }

    public function view(User $user): bool
    {
        return $this->context->can('roles.manage');
    }

    public function create(User $user): bool
    {
        return $this->context->can('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        // The super role's matrix is read-only (all-on, via the bypass) —
        // no one edits it, not even another super.
        return $this->context->can('roles.manage') && ! $role->is_super;
    }

    public function delete(User $user, Role $role): bool
    {
        // System roles (super included) are permanent fixtures.
        return $this->context->can('roles.manage') && ! $role->is_system;
    }
}
