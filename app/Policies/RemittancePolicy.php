<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Remittance;
use App\Models\User;
use App\Support\TenantContext;

/**
 * The daily income form (docs/plans/cash-controls.md, wave 4).
 *
 *  - `cash.remit`   — hand over one's own collections, see one's own handovers.
 *  - `cash.receive` — receive someone ELSE's handover into one's till; the
 *    owner (super) may receive their own. Repaying a shortage is also a
 *    receipt of cash, so it needs this too.
 *  - `cash.view`    — see every handover.
 *  - writing a shortage off is a loss decision: `vouchers.approve` (the
 *    managers who approve spending), never the cashier who counted it.
 */
class RemittancePolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->canAny('cash.view', 'cash.remit', 'cash.receive');
    }

    public function view(User $user, Remittance $remittance): bool
    {
        return $this->context->canAny('cash.view', 'cash.receive')
            || ($remittance->collector_user_id === $user->id && $this->context->can('cash.remit'));
    }

    public function create(User $user): bool
    {
        return $this->context->can('cash.remit');
    }

    public function receive(User $user, Remittance $remittance): bool
    {
        return $remittance->isSubmitted()
            && $this->context->can('cash.receive')
            && ($remittance->collector_user_id !== $user->id || $this->context->isSuper());
    }

    public function repayShortage(User $user, Remittance $remittance): bool
    {
        return $remittance->shortage_status === 'open' && $this->context->can('cash.receive');
    }

    public function writeOffShortage(User $user, Remittance $remittance): bool
    {
        return $remittance->shortage_status === 'open'
            && $this->context->can('vouchers.approve')
            && ($remittance->collector_user_id !== $user->id || $this->context->isSuper());
    }
}
