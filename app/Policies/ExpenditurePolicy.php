<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Expenditure;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Expenditures after docs/plans/expenditures-and-spending-controls.md.
 * Nobody records one directly any more (E1 — every cash-out is a bon), so
 * there is no `create`. Edits and voids (E7) belong to admin and owner —
 * `expenditures.update` / `expenditures.delete` — and never to the person
 * who requested or recorded the money: maker ≠ checker. Budgets have their
 * own `budgets.manage`; reopening a closed month is the owner's alone.
 */
class ExpenditurePolicy
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

    /** Re-categorise / fix description and notes, with a reason. */
    public function update(User $user, Expenditure $expenditure): bool
    {
        return $this->context->can('expenditures.update') && ! $this->isRequester($user, $expenditure);
    }

    /** Void a legacy expenditure, with a reason. Never a hard delete. */
    public function void(User $user, Expenditure $expenditure): bool
    {
        return $expenditure->source === Expenditure::SOURCE_LEGACY
            && $this->context->can('expenditures.delete')
            && ! $this->isRequester($user, $expenditure);
    }

    public function viewDashboard(User $user): bool
    {
        return $this->context->can('expenditures.dashboard');
    }

    public function manageBudgets(User $user): bool
    {
        return $this->context->can('budgets.manage');
    }

    /** Reopen (or close again) a month — the owner only (E6). */
    public function manageMonths(User $user): bool
    {
        return $this->context->isSuper();
    }

    private function isRequester(User $user, Expenditure $expenditure): bool
    {
        return $expenditure->user_id === $user->id
            || ($expenditure->voucher_id !== null && $expenditure->voucher?->requested_by === $user->id);
    }
}
