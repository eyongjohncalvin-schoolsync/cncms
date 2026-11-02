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
 *    (`cash.close_days`) may close an absent cashier's.
 *  - `cash.manage_accounts` — set up accounts (safe, bank, wallets) and their
 *    opening balances: a configuration decision, like the voucher limits.
 *  - reopening a closed day is a supervisor's call: `cash.close_days`.
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
        return $this->context->can('cash.manage_accounts');
    }

    public function update(User $user, CashAccount $account): bool
    {
        return $this->context->can('cash.manage_accounts');
    }

    public function transfer(User $user): bool
    {
        return $this->context->can('cash.receive');
    }

    /**
     * The holder counts their own till; a supervisor (`cash.close_days`)
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
            || $this->context->can('cash.close_days');
    }

    /** A month's statement (bank, wallet) or count (safe): whoever reconciles the accounts (cash.reconcile). */
    public function countStatement(User $user, CashAccount $account): bool
    {
        return ! $account->isTill() && $this->context->can('cash.reconcile');
    }

    public function reopenDay(User $user, CashAccount $account): bool
    {
        return $this->context->can('cash.close_days');
    }
}
