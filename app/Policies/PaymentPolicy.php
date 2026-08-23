<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Payments: everyone with tenant access can view. Agents may additionally
 * record (create) payments — they just enter 'pending' verification
 * (see App\Services\PaymentService::create()) rather than being
 * auto-approved. Only super/admin/manager may edit a recorded payment, and
 * only super/admin may delete one, mirroring the "agents record, only
 * admin/super edit or delete" convention SKILL.md documents for the
 * analogous Resources/Expenditures module. Workers cannot record payments
 * at all ("workers are limited").
 */
class PaymentPolicy
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
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }

    public function delete(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }

    public function verify(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }

    /**
     * Attaching receipt evidence is evidence-gathering, not an edit to the
     * payment record itself — the same roles that may record a payment
     * (business-rules.md section 5: "Agent optionally attaches receipt
     * photo") may attach a receipt to it, unlike update()/delete() which
     * stay office-only.
     */
    public function attachReceipt(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager', 'agent');
    }
}
