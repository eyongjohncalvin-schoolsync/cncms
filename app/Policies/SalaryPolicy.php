<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Salaries (branches v2 D8): `salaries.manage` sets salaries — a fenced
 * manager for their own branch's staff only; headquarters (anyone acting for
 * the company) also keeps the grades and decides salaries outside a grade's
 * range.
 */
class SalaryPolicy
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantUserPolicy $staff,
    ) {}

    public function setFor(User $user, TenantUser $tenantUser): bool
    {
        return $this->context->can('salaries.manage') && $this->staff->worksInMyBranch($tenantUser);
    }

    /** The grades and approvals: headquarters' call. */
    public function manageScale(User $user): bool
    {
        return $this->context->can('salaries.manage') && $this->context->actsForCompany();
    }
}
