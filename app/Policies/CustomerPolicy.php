<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Customers: everyone with tenant access can view (agents/workers are
 * view-only per SKILL.md's role table); only super/admin/manager can
 * create/edit/delete customer records, matching api-spec.md sections
 * 2.3/2.4 ("role: admin, manager, super").
 */
class CustomerPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('customers.view');
    }

    public function view(User $user): bool
    {
        return $this->context->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('customers.create');
    }

    public function update(User $user): bool
    {
        return $this->context->can('customers.update');
    }

    public function delete(User $user): bool
    {
        return $this->context->can('customers.delete');
    }

    /**
     * Archive (soft-delete) / restore a customer — the customer-deletion
     * deliberation's reversible, non-financial lifecycle action. Same
     * super/admin/manager gate as delete() and the reversible status
     * actions (disconnect/suspend/reconnect): archiving moves no ledger
     * figure, so it deliberately does NOT go through the arrears-adjustment
     * maker-checker (that is the money-movement control, not the
     * destructive-action control). The type-the-name confirm + required
     * reason on the modal, plus the full audit row, are the safety here.
     */
    public function archive(User $user): bool
    {
        return $this->context->can('customers.archive');
    }

    public function restore(User $user): bool
    {
        return $this->context->can('customers.archive');
    }

    /**
     * Printing a customer's bill slip (business-rules.md section 3 /
     * api-spec.md section 9.1): role table row "Print bills" allows
     * super/admin/manager/agent — workers cannot print.
     */
    public function printBill(User $user): bool
    {
        return $this->context->can('customers.print_bill');
    }

    /**
     * Disconnect (App\Services\CustomerStatusService): 2026-08 mobile
     * field-ops widening — takes the target $customer (unlike suspend()/
     * reconnect() below) so an agent's grant can be scoped to their own
     * zone rather than a blanket role widening, exactly mirroring
     * PaymentPolicy::verify()'s "agent may act only within their own zone"
     * shape. super/admin/manager stay unrestricted, same as before.
     * business-rules.md section 1 now reads "admin, manager, super roles
     * (agent, zone-scoped, for disconnect only)". DisconnectCustomerRequest::
     * authorize() already resolves the route-bound Customer before calling
     * this, so it's a no-op change on the request side.
     *
     * Disconnect specifically (not suspend/reconnect) is the one status
     * action an agent needs to execute in the field — a non-paying
     * customer visited door-to-door, cut on the spot, recorded
     * immediately. Reconnect stays office-only because it also moves money
     * (arrears/fine collection — see reconnect()'s doc comment); suspend
     * stays office-only because its reasons are non-payment-unrelated
     * (tv_problem, customer_request, etc.) and carry the prepaid-pause
     * admin choice (prepaid-pause-handling.md section 5), neither of which
     * is a field-triggered decision.
     */
    public function disconnect(User $user, Customer $customer): bool
    {
        // RBAC v2: the office gate is now the `customers.change_status`
        // catalog permission; the agent zone-scoped branch stays an additive
        // OR (agent is NOT seeded `customers.change_status` — see Wave 2
        // rules and TenantContext::zoneId).
        return $this->context->can('customers.change_status')
            || ($this->context->role === 'agent'
                && $this->context->zoneId !== null
                && $customer->zone_id === $this->context->zoneId);
    }

    public function suspend(User $user): bool
    {
        return $this->context->can('customers.change_status');
    }

    public function reconnect(User $user): bool
    {
        return $this->context->can('customers.change_status');
    }

    /**
     * Gates the dedicated /disconnections bulk-status-action page
     * (App\Http\Controllers\DisconnectionsController) as a whole — same
     * super/admin/manager roles as the individual status actions, since the
     * page exists specifically to drive them in bulk.
     */
    public function viewStatusBoard(User $user): bool
    {
        return $this->context->can('customers.status_board');
    }

    /**
     * The arrears-based "flagged for non-payment" eligibility view on
     * /disconnections?eligible=1 (App\Services\CustomerEligibilityService)
     * — deliberately broader than viewStatusBoard(): a field `agent` needs
     * to see which of THEIR OWN zone's customers have crossed the 3x-bill
     * arrears threshold so they can act on it in the field
     * (DisconnectionsController scopes an agent's view to their own zone
     * via Agent::zone_id; office roles see every zone with a filter).
     * Executing the disconnect itself still goes through
     * disconnect()/bulkDisconnect(), which stay super/admin/manager-only —
     * agents can SEE the list, only office staff pulls the trigger.
     */
    public function viewEligibilityBoard(User $user): bool
    {
        return $this->context->can('customers.eligibility_board');
    }

    /**
     * Deliberately NOT widened alongside disconnect() above — bulk-select
     * is an office-workboard interaction (DisconnectionsController), not a
     * mobile one; an agent has no route to this endpoint from the app and
     * stays office/manager-gated here, same as suspend()/reconnect().
     */
    public function bulkDisconnect(User $user): bool
    {
        return $this->context->can('customers.change_status');
    }

    public function bulkSuspend(User $user): bool
    {
        return $this->context->can('customers.change_status');
    }

    public function bulkReconnect(User $user): bool
    {
        return $this->context->can('customers.change_status');
    }
}
