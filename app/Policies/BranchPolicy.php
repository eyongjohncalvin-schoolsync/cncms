<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Branch;
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
        return $this->context->can('branches.view');
    }

    public function view(User $user): bool
    {
        return $this->context->can('branches.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('branches.manage') && $this->context->actsForCompany();
    }

    public function update(User $user): bool
    {
        return $this->context->can('branches.manage') && $this->context->actsForCompany();
    }

    public function delete(User $user): bool
    {
        return $this->context->can('branches.manage') && $this->context->actsForCompany();
    }

    /**
     * A branch's own contact details, MoMo number, fees and prices
     * (branches v2 D4): whoever manages branches, for any branch; a branch's
     * own staff holding `branches.settings`, for their branch only.
     * Headquarters has none — its values are the company settings.
     */
    public function manageSettings(User $user, Branch $branch): bool
    {
        if ($branch->is_headquarters) {
            return false;
        }

        return ($this->context->can('branches.manage') && $this->context->actsForCompany())
            || ($this->context->can('branches.settings') && $this->context->isFenced() && $this->context->branchId === $branch->id);
    }
}
