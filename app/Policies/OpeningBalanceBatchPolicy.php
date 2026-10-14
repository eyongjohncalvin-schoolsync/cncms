<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Maker-checker for opening balances, mirroring ArrearsAdjustmentPolicy:
 * `opening_balances.enter` prepares and submits a batch; a DIFFERENT person
 * holding `opening_balances.approve` approves or rejects it. The owner
 * (super) may approve their own submission — the same carve-out arrears
 * adjustments have — and the UI asks them to confirm when they do.
 */
class OpeningBalanceBatchPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->canAny('opening_balances.view', 'opening_balances.enter', 'opening_balances.approve');
    }

    public function create(User $user): bool
    {
        return $this->context->can('opening_balances.enter');
    }

    /** Edit entries / go-live month, submit, or discard — drafts only. */
    public function update(User $user, OpeningBalanceBatch $batch): bool
    {
        return $batch->isDraft() && $this->context->can('opening_balances.enter');
    }

    public function approve(User $user, OpeningBalanceBatch $batch): bool
    {
        return $batch->isPendingApproval()
            && $this->context->can('opening_balances.approve')
            && ($user->id !== $batch->submitted_by || $this->context->isSuper());
    }

    public function reject(User $user, OpeningBalanceBatch $batch): bool
    {
        return $this->approve($user, $batch);
    }

    /**
     * Pull an approved batch back to draft (removing the starting
     * manuscripts it wrote). The service additionally refuses once the
     * first bill run has locked it.
     */
    public function reopen(User $user, OpeningBalanceBatch $batch): bool
    {
        return $batch->isApproved() && $this->context->can('opening_balances.approve');
    }
}
