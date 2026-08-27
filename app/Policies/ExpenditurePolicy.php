<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Expenditures: everyone with tenant access can view (needed for the
 * expenditure list and the dashboard's underlying data). Per
 * business-rules.md section 10's role table: agents may additionally
 * record ("Record expense": super/admin/manager/agent), but only
 * super/admin may edit or delete a recorded expenditure ("Edit/delete
 * expense": super/admin) — expenditures have no verification workflow to
 * fall back on, so this is the only control. Viewing the P&L dashboard
 * itself is a step up from viewing the raw list ("View P&L dashboard":
 * super/admin/manager — agents excluded).
 */
class ExpenditurePolicy
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
        return $this->context->isAnyOf('super', 'admin', 'manager', 'agent');
    }

    public function update(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    public function delete(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    public function viewDashboard(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }
}
