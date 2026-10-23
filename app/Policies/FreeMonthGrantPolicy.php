<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FreeMonthGrant;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Free months (docs/plans/installation-and-promotions.md, phase 2).
 *
 *  - create (compensation, D12) — `installations.manage`.
 *  - approve / reject — `installations.approve`, and never by whoever asked
 *    (maker ≠ checker, D8), the owner excepted exactly like arrears
 *    adjustments and installation waivers. Acts on the whole request
 *    (all rows sharing the grant's group_uuid).
 */
class FreeMonthGrantPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function create(User $user): bool
    {
        return $this->context->can('installations.manage');
    }

    public function approve(User $user, FreeMonthGrant $grant): bool
    {
        return $grant->status === FreeMonthGrant::STATUS_PENDING_APPROVAL
            && $this->context->can('installations.approve')
            && ($user->id !== $grant->requested_by || $this->context->isSuper());
    }

    public function reject(User $user, FreeMonthGrant $grant): bool
    {
        return $this->approve($user, $grant);
    }
}
