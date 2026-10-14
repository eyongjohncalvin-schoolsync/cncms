<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Suppliers: anyone who can see expenses can see the supplier list (to
 * pick one); `suppliers.manage` adds, edits, approves and deactivates.
 * "Not the person who added it" for approval is enforced in
 * SupplierService.
 */
class SupplierPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('expenditures.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('suppliers.manage') || $this->context->can('expenditures.create');
    }

    public function update(User $user): bool
    {
        return $this->context->can('suppliers.manage');
    }

    public function approve(User $user): bool
    {
        return $this->context->can('suppliers.manage');
    }
}
