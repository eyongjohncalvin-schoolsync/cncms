<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * The /reports feature (Daily/Weekly/Monthly operational + financial
 * reporting) is deliberately open to `agent` too — unlike /resources (the
 * monthly-only P&L dashboard, ExpenditurePolicy::viewDashboard()), which is
 * office-only. Agents need their own collections-today figures in the
 * field; they just get a narrower, zone-fenced payload from the same route
 * (see App\Services\ReportService's zone-vs-branch fencing and
 * App\Http\Controllers\ReportController::defaultTierForRole()). `worker` is
 * excluded from all of it — mirrors ManuscriptPolicy's viewAny()/view() role
 * set exactly, since reports surface the same kind of billing/collections
 * data manuscripts do.
 *
 * export() (Monthly-tier PDF only — see ReportController::export()) is
 * narrower than view(): super/admin/manager, matching
 * ManuscriptPolicy::export()'s role set. Agents can view their own daily
 * figures but never export the monthly report.
 *
 * view() also carries one additive OR for the Investor tier (see
 * references/rbac-permissions.md section 7): `tenant_users.is_investor`
 * grants exactly this one capability — view reports — regardless of the
 * row's `role` (which sits at the `worker` floor by convention for an
 * investor row, denying everything else by default). Not folded into
 * isAnyOf(...) above since it isn't a role at all, just a per-user flag,
 * same shape as PaymentPolicy::create()'s can_record_payments OR-branch.
 * export() is deliberately NOT widened the same way — investors can view,
 * never export, per that doc's explicit "exactly one capability" framing.
 */
class ReportPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function view(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager', 'agent')
            || $this->context->tenantUser->is_investor;
    }

    public function export(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }
}
