<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Customers: everyone with tenant access can view (agents/workers are
 * view-only per SKILL.md's role table); only super/admin/manager can
 * create/edit/delete customer records, matching api-spec.md sections
 * 2.3/2.4 ("role: admin, manager, super").
 */
class CustomerPolicy
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
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }

    public function update(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }

    public function delete(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }
}
