<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashAccount;
use App\Models\User;
use App\Support\TenantContext;

/**
 * The cash book's accounts (docs/plans/cash-controls.md, wave 4).
 *
 *  - `cash.view`      — every account, balance and ledger.
 *  - `cash.receive`   — move money between accounts (deposits, transfers)
 *    and close one's OWN till; the owner (super) and a supervisor
 *    (`vouchers.approve`) may close an absent cashier's.
 *  - `company.update` — set up accounts (safe, bank, wallets) and their
 *    opening balances: a configuration decision, like the voucher limits.
 *  - reopening a closed day is a supervisor's call: `vouchers.approve`.
 */
class CashAccountPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('cash.view');
    }

    public function view(User $user, CashAccount $account): bool
    {
        return $this->context->can('cash.view') || ($account->holder_user_id === $user->id && $this->context->can('cash.receive'));
    }

    public function create(User $user): bool
    {
        return $this->context->can('company.update');
    }

    public function update(User $user, CashAccount $account): bool
    {
        return $this->context->can('company.update');
    }

    public function transfer(User $user): bool
    {
        return $this->context->can('cash.receive');
    }

    /**
     * The holder counts their own till; a supervisor (`vouchers.approve`)
     * counts an absent cashier's — any shortfall is still the holder's
     * (owner, 2026-10-29).
     */
    public function closeDay(User $user, CashAccount $account): bool
    {
        if (! $account->isTill()) {
            return false;
        }

        return ($account->holder_user_id === $user->id && $this->context->can('cash.receive'))
            || $this->context->isSuper()
            || $this->context->can('vouchers.approve');
    }

    /** A month's statement (bank, wallet) or count (safe): whoever sees the cash book. */
    public function countStatement(User $user, CashAccount $account): bool
    {
        return ! $account->isTill() && $this->context->can('cash.view');
    }

    public function reopenDay(User $user, CashAccount $account): bool
    {
        return $this->context->can('vouchers.approve');
    }
}
