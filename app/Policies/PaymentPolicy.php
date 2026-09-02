<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
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
 * at all by role — EXCEPT the narrow, explicit per-user grant below
 * (tenant_users.can_record_payments), for the one real "Secretary"
 * front-desk case a worker legitimately needs to take payments. This is
 * deliberately NOT a general permission-grant system — see that column's
 * migration doc comment — it is exactly one flag for exactly one case.
 */
class PaymentPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('payments.view');
    }

    public function view(User $user): bool
    {
        return $this->context->can('payments.view');
    }

    /**
     * The worker branch here is an explicit per-user grant, not a role
     * widening — a plain `'worker'` added to isAnyOf(...) below would open
     * payment-recording to EVERY worker, which is not what was asked for.
     * Only a worker whose own tenant_users.can_record_payments flag is true
     * (settable only by super/admin — see UpdateTenantUserRequest) may
     * record a payment; every other worker still cannot. The actual branch
     * fence on WHICH customers they can pay is still enforced separately
     * and incidentally by CustomerRepository's ScopesByBranch (defense in
     * depth) — this check is the real "can this worker record a payment AT
     * ALL" authorization decision.
     */
    public function create(User $user): bool
    {
        // RBAC v2: the role gate is now the `payments.create` catalog
        // permission; the worker `can_record_payments` grant stays an
        // additive OR (a per-user flag — worker's role is NOT seeded
        // `payments.create`; see this method's docblock and Wave 2 rules).
        return $this->context->can('payments.create')
            || ($this->context->role === 'worker' && $this->context->tenantUser->can_record_payments === true);
    }

    /**
     * Same roles as create(), including the worker+flag grant — bulk entry
     * is the same "record a payment" action repeated per customer, not a
     * distinct capability.
     */
    public function bulkCreate(User $user): bool
    {
        return $this->context->can('payments.create')
            || ($this->context->role === 'worker' && $this->context->tenantUser->can_record_payments === true);
    }

    public function update(User $user): bool
    {
        return $this->context->can('payments.update');
    }

    public function delete(User $user): bool
    {
        return $this->context->can('payments.delete');
    }

    /**
     * Takes the target $payment (unlike the class-level checks above) so an
     * agent's grant can be scoped to their own zone rather than being a
     * blanket role widening: an agent may verify a payment only for a
     * customer in the same zone as their own Agent row
     * (TenantContext::zoneId). super/admin/manager are unrestricted, same
     * as before. VerifyPaymentRequest::authorize() already resolves the
     * route-bound Payment before calling this.
     */
    public function verify(User $user, Payment $payment): bool
    {
        // RBAC v2: the office gate is now the `payments.verify` catalog
        // permission; the agent zone-scoped branch stays an additive OR
        // (agent is NOT seeded `payments.verify` — see Wave 2 rules).
        return $this->context->can('payments.verify')
            || ($this->context->role === 'agent'
                && $this->context->zoneId !== null
                && $payment->customer->zone_id === $this->context->zoneId);
    }

    /**
     * Widened to include `agent` alongside verify() above — but ONLY the
     * class-level "may this actor use bulk-verify at all" gate. The actual
     * per-payment zone fence for an agent is NOT expressible here (bulk
     * approval has no single target model) and MUST be re-checked per item
     * inside App\Services\PaymentVerificationService::verifyMany()'s loop —
     * see that method's doc comment. Without that per-item check, an agent
     * could otherwise bypass verify()'s zone fence entirely by hitting the
     * bulk-verify endpoint with UUIDs of payments outside their own zone;
     * this class-level check alone does NOT protect against that.
     */
    public function bulkVerify(User $user): bool
    {
        // RBAC v2: `payments.verify` is the office gate; `agent` stays in
        // this class-level gate as an additive OR (agent is NOT seeded
        // `payments.verify`) exactly as before — the real per-payment zone
        // fence for an agent is re-checked per item in
        // App\Services\PaymentVerificationService::verifyMany() (untouched).
        return $this->context->can('payments.verify')
            || $this->context->role === 'agent';
    }

    /**
     * Attaching receipt evidence is evidence-gathering, not an edit to the
     * payment record itself — the same roles that may record a payment
     * (business-rules.md section 5: "Agent optionally attaches receipt
     * photo"), including the worker+flag grant, may attach a receipt to it,
     * unlike update()/delete() which stay office-only.
     */
    public function attachReceipt(User $user): bool
    {
        return $this->context->can('payments.create')
            || ($this->context->role === 'worker' && $this->context->tenantUser->can_record_payments === true);
    }
}
