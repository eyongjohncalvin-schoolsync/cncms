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
}
