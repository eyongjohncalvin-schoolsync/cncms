<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Users & Roles (tenant-scoped membership rows): only super/admin can view
 * or manage the tenant's user list, matching business-rules.md section 10
 * ("Manage users | YES | YES | -- | -- | --") and the nav spec's
 * "SETTINGS [admin only]". Note this is stricter than most other tenant
 * policies (which allow everyone to view) — user management is not a
 * view-only-for-others page.
 */
class TenantUserPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    public function view(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    public function create(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    public function update(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    public function deactivate(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }
}
