<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Agents (field collectors): everyone with tenant access can view; only
 * super/admin/manager manage field agent records (assign zones, salary,
 * status), consistent with the Agents management page being an office
 * function per web-admin-spec.md section 3.9.
 */
class AgentPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('agents.view');
    }

    public function view(User $user): bool
    {
        return $this->context->can('agents.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('agents.manage');
    }

    public function update(User $user): bool
    {
        return $this->context->can('agents.manage');
    }

    public function delete(User $user): bool
    {
        return $this->context->can('agents.manage');
    }
}
