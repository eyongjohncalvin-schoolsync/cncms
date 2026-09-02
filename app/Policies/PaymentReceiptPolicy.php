<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentReceipt;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Payment receipts (Wave 2 of docs/plans/payment-receipts-and-whatsapp.md).
 *
 * RBAC v2: both abilities resolve through TenantContext::can() against the
 * per-role permission matrix, same as PaymentPolicy.
 *
 *  - view  → `payments.view` (every role that can see a payment can see and
 *            download its receipt — the receipt carries no data the payment
 *            row doesn't already expose to that role).
 *  - issue → `payments.issue_receipt` (the manual "Issue / re-issue receipt"
 *            action — seeded to the same roles that hold `payments.verify`).
 *            Auto-issue on verify() is NOT gated here: it is a side effect of
 *            an already-authorised approval, performed by the service, never
 *            through this policy.
 *
 * Registered explicitly in App\Providers\AppServiceProvider (alongside
 * ReportPolicy / RolePolicy) rather than left to naming-convention
 * discovery — keeps all policy wiring visible in one place.
 */
class PaymentReceiptPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function view(User $user, PaymentReceipt $receipt): bool
    {
        return $this->context->can('payments.view');
    }

    public function issue(User $user): bool
    {
        return $this->context->can('payments.issue_receipt');
    }
}
