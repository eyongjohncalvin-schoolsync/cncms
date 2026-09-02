<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Zones: everyone with tenant access can view; only super/admin/manager
 * can create/edit/delete geography setup.
 */
class ZonePolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('zones.view');
    }

    public function view(User $user): bool
    {
        return $this->context->can('zones.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('zones.manage');
    }

    public function update(User $user): bool
    {
        return $this->context->can('zones.manage');
    }

    public function delete(User $user): bool
    {
        return $this->context->can('zones.manage');
    }
}
