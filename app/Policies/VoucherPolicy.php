<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Voucher;
use App\Services\VoucherService;
use App\Support\TenantContext;

/**
 * Vouchers / bons de caisse (docs/plans/cash-controls.md, wave 5). Three
 * different people for three steps: whoever requests never approves or pays
 * their own voucher (the owner excepted for paying). Above-limit approvals
 * belong to the investors — the `is_investor` flag, not a permission — and
 * VoucherService::canDecide() is the one rule for who may decide now.
 *
 * Context is resolved per call (not injected) for the same reason as the
 * cash controllers: policies may outlive one request in long-lived workers.
 */
class VoucherPolicy
{
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function viewAny(User $user): bool
    {
        return $this->context()->canAny('vouchers.request', 'vouchers.approve', 'vouchers.pay', 'cash.view')
            || (bool) $this->context()->tenantUser->is_investor;
    }

    public function view(User $user, Voucher $voucher): bool
    {
        return $voucher->requested_by === $user->id
            || $this->context()->canAny('vouchers.approve', 'vouchers.pay', 'cash.view')
            || in_array($user->id, $voucher->requiredApproverIds(), true);
    }

    public function create(User $user): bool
    {
        return $this->context()->can('vouchers.request');
    }

    public function decide(User $user, Voucher $voucher): bool
    {
        return app(VoucherService::class)->canDecide($voucher, $user, $this->context());
    }

    public function cancel(User $user, Voucher $voucher): bool
    {
        return $voucher->isPending() && $voucher->requested_by === $user->id;
    }

    public function pay(User $user, Voucher $voucher): bool
    {
        return $voucher->status === 'approved'
            && $this->context()->can('vouchers.pay')
            && ($voucher->requested_by !== $user->id || $this->context()->isSuper());
    }

    public function settle(User $user, Voucher $voucher): bool
    {
        return $voucher->kind === Voucher::KIND_ADVANCE
            && $voucher->status === 'paid'
            && $this->context()->can('vouchers.pay')
            && ($voucher->requested_by !== $user->id || $this->context()->isSuper());
    }
}
