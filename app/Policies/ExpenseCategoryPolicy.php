<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Expense categories: everyone with tenant access can view/list (needed to
 * populate the category dropdown on the expenditure entry form for every
 * role that can record an expense). Creating, editing, and deactivating
 * categories is office-only, per business-rules.md section 10's "Manage
 * categories": super/admin.
 */
class ExpenseCategoryPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('expenditures.view');
    }

    public function view(User $user): bool
    {
        return $this->context->can('expenditures.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('expense_categories.manage');
    }

    public function update(User $user): bool
    {
        return $this->context->can('expense_categories.manage');
    }

    /**
     * Deactivation (api-spec.md section 6.6 — not a hard delete).
     */
    public function delete(User $user): bool
    {
        return $this->context->can('expense_categories.manage');
    }
}
