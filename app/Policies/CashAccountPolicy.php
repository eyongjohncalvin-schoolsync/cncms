<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Cash desk. Anyone who handles money (records payments or expenses) has
 * their own till: they see it, hand cash over, receive it and count it.
 * Seeing every account and deciding on other people's counts and deposits
 * needs `cash.view` / `cash.manage`; owner-level decisions need
 * `cash.approve_large`; closing a month needs `periods.lock`. "Not your
 * own" is enforced in CashDeskService.
 */
class CashAccountPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function useOwnTill(User $user): bool
    {
        return $this->context->can('payments.create')
            || $this->context->can('expenditures.create')
            || $this->context->can('cash.manage')
            || ($this->context->role === 'worker' && $this->context->tenantUser->can_record_payments === true);
    }

    public function viewDesk(User $user): bool
    {
        return $this->context->can('cash.view');
    }

    public function manage(User $user): bool
    {
        return $this->context->can('cash.manage');
    }

    public function approveLarge(User $user): bool
    {
        return $this->context->can('cash.approve_large');
    }

    public function lockPeriods(User $user): bool
    {
        return $this->context->can('periods.lock');
    }
}
