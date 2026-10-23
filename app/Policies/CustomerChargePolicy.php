<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerCharge;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Installation charges (docs/plans/installation-and-promotions.md, phase 1).
 *
 *  - create / requestWaiver — `installations.manage`.
 *  - recordPayment          — `payments.create` (an instalment is a payment
 *                             like any other: verification, receipt, handover).
 *  - approveWaiver / rejectWaiver — `installations.approve`, and the actor
 *    must not be whoever asked for the waiver (maker ≠ checker, D8). The
 *    owner (super) may decide their own request — the same carve-out as
 *    App\Policies\ArrearsAdjustmentPolicy, so the owner is never deadlocked.
 */
class CustomerChargePolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function create(User $user): bool
    {
        return $this->context->can('installations.manage');
    }

    public function recordPayment(User $user, CustomerCharge $charge): bool
    {
        return $charge->isOpen() && $this->context->can('payments.create');
    }

    public function requestWaiver(User $user, CustomerCharge $charge): bool
    {
        return $charge->isOpen() && ! $charge->hasPendingWaiver() && $this->context->can('installations.manage');
    }

    public function approveWaiver(User $user, CustomerCharge $charge): bool
    {
        return $charge->isOpen()
            && $charge->hasPendingWaiver()
            && $this->context->can('installations.approve')
            && ($user->id !== $charge->waiver_requested_by || $this->context->isSuper());
    }

    public function rejectWaiver(User $user, CustomerCharge $charge): bool
    {
        return $this->approveWaiver($user, $charge);
    }

    /**
     * A fee below the offer's (phase 2, D8) — approved by someone other than
     * whoever entered it (the owner excepted).
     */
    public function approveFee(User $user, CustomerCharge $charge): bool
    {
        return $charge->hasPendingFeeApproval()
            && $this->context->can('installations.approve')
            && ($user->id !== $charge->requested_by || $this->context->isSuper());
    }

    public function rejectFee(User $user, CustomerCharge $charge): bool
    {
        return $this->approveFee($user, $charge);
    }

    /** "Refund installation" (D9): asked for by the office, with a reason. */
    public function requestRefund(User $user, CustomerCharge $charge): bool
    {
        return in_array($charge->status, [CustomerCharge::STATUS_OPEN, CustomerCharge::STATUS_PAID], true)
            && ! $charge->hasPendingRefund()
            && bccomp($charge->paidAmount(), '0', 2) > 0
            && $this->context->can('installations.manage');
    }

    public function approveRefund(User $user, CustomerCharge $charge): bool
    {
        return $charge->hasPendingRefund()
            && $this->context->can('installations.approve')
            && ($user->id !== $charge->refund_requested_by || $this->context->isSuper());
    }

    public function rejectRefund(User $user, CustomerCharge $charge): bool
    {
        return $this->approveRefund($user, $charge);
    }
}
