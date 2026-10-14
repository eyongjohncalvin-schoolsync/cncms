<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Expenditure;
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
        return $this->context->can('expenditures.view');
    }

    public function view(User $user): bool
    {
        return $this->context->can('expenditures.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('expenditures.create');
    }

    /**
     * Editing is only possible while the expense still waits for its
     * receipt or first approval — by whoever recorded it, or anyone with
     * `expenditures.update`. Approved expenses are frozen (a database
     * trigger enforces it too); they are voided and recorded again.
     */
    public function update(User $user, ?Expenditure $expenditure = null): bool
    {
        if ($expenditure === null) {
            return $this->context->can('expenditures.update');
        }

        if (! in_array($expenditure->status, [Expenditure::STATUS_PENDING_EVIDENCE, Expenditure::STATUS_PENDING_APPROVAL], true)) {
            return false;
        }

        return $expenditure->user_id === $user->id || $this->context->can('expenditures.update');
    }

    /** Adding the receipt photo: the recorder, or anyone who can edit. */
    public function attachReceipt(User $user, Expenditure $expenditure): bool
    {
        return $expenditure->user_id === $user->id || $this->context->can('expenditures.update');
    }

    /**
     * First approval (or rejection) needs `expenditures.approve`; the
     * second approval on a large expense needs `expenditures.approve_large`.
     * Approving your own expense is refused in ExpenseControlService.
     */
    public function approve(User $user, Expenditure $expenditure): bool
    {
        return $expenditure->status === Expenditure::STATUS_PENDING_SECOND_APPROVAL
            ? $this->context->can('expenditures.approve_large')
            : $this->context->can('expenditures.approve');
    }

    public function reject(User $user, Expenditure $expenditure): bool
    {
        return $this->context->can('expenditures.approve') || $this->context->can('expenditures.approve_large');
    }

    /**
     * Voiding keeps the record and stops it counting. Anyone with
     * `expenditures.void`; the recorder may also withdraw their own
     * expense while nobody has approved it yet.
     */
    public function void(User $user, Expenditure $expenditure): bool
    {
        if ($this->context->can('expenditures.void')) {
            return true;
        }

        return $expenditure->user_id === $user->id
            && $expenditure->isPending()
            && $expenditure->first_approved_by === null;
    }

    /** Kept for older clients: "delete" now means void. */
    public function delete(User $user, ?Expenditure $expenditure = null): bool
    {
        return $expenditure !== null ? $this->void($user, $expenditure) : $this->context->can('expenditures.void');
    }

    /** Budgets, regular expense templates and the approval limits. */
    public function manageResourcesSettings(User $user): bool
    {
        return $this->context->can('resources.settings');
    }

    public function viewDashboard(User $user): bool
    {
        return $this->context->can('expenditures.dashboard');
    }
}
