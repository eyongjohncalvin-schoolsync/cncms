<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Branches: everyone with tenant access can view (needed to populate the
 * Zone create/edit branch picker for every role); creating/editing/deleting
 * a branch is office-only (super/admin), per
 * branches-and-locations.md section 8 — unlike ZonePolicy, `manager` is
 * deliberately NOT included here. Branch creation is a structural decision
 * about how the operator's offices are organized, not day-to-day geography
 * upkeep like zones.
 */
class BranchPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    public function update(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    public function delete(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }
}
