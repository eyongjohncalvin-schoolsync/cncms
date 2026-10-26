<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Agent;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Users & Roles (tenant-scoped membership rows): only super/admin can view
 * or manage the tenant's user list, matching business-rules.md section 10
 * ("Manage users | YES | YES | -- | -- | --") and the nav spec's
 * "SETTINGS [admin only]". Note this is stricter than most other tenant
 * policies (which allow everyone to view) — user management is not a
 * view-only-for-others page.
 *
 * Branches v2 (D7): someone fenced to a branch manages that branch's staff
 * only — the staff fenced to it and the agents of its zones.
 */
class TenantUserPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('users.view');
    }

    public function view(User $user): bool
    {
        return $this->context->can('users.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('users.manage');
    }

    public function update(User $user, ?TenantUser $tenantUser = null): bool
    {
        return $this->context->can('users.manage') && ($tenantUser === null || $this->worksInMyBranch($tenantUser));
    }

    public function deactivate(User $user, ?TenantUser $tenantUser = null): bool
    {
        return $this->context->can('users.manage') && ($tenantUser === null || $this->worksInMyBranch($tenantUser));
    }

    public function activate(User $user, ?TenantUser $tenantUser = null): bool
    {
        return $this->deactivate($user, $tenantUser);
    }

    /**
     * Only an account with no history — StaffRemovalService::delete() checks that.
     */
    public function delete(User $user, ?TenantUser $tenantUser = null): bool
    {
        return $this->deactivate($user, $tenantUser);
    }

    /**
     * Unfenced: everyone. Fenced: staff fenced to my branch, and agents whose
     * zone is in my branch (the Agent query is branch-scoped already).
     */
    public function worksInMyBranch(TenantUser $tenantUser): bool
    {
        if (! $this->context->isFenced()) {
            return true;
        }

        return $tenantUser->branch_id === $this->context->branchId
            || ($tenantUser->role === 'agent' && Agent::query()->where('user_id', $tenantUser->user_id)->exists());
    }
}
