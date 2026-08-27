<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Complaint Desk permissions — see references/complaint-desk.md section 3.
 *
 * Visibility is deliberately universal: every role sees every complaint, no
 * per-zone/per-branch fencing (the spec calls this out explicitly as
 * intentional social pressure at this staff size, not an oversight) — so
 * viewAny()/view() are unconditionally true for any authenticated tenant
 * user, same shape as ExpenditurePolicy's "everyone can view" rows.
 *
 * create() is the one deliberately ungated action in this whole app —
 * "the one feature `worker` gets genuine capability in" per the spec.
 *
 * resolve()/reopen() are restricted to super/admin/manager AND explicitly
 * exclude the submitter, even if the submitter happens to hold one of those
 * roles — closes the self-resolution/self-reopen gaming path the spec calls
 * out as a real security-adjacent rule, not just UI hiding. This is the
 * standing caution already documented in references/rbac-permissions.md
 * from this app's own history, applied here.
 */
class ComplaintPolicy
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
        return true;
    }

    public function resolve(User $user, Complaint $complaint): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager') && $complaint->submitted_by !== $user->id;
    }

    public function reopen(User $user, Complaint $complaint): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager') && $complaint->submitted_by !== $user->id;
    }

    /**
     * Linking a genuine duplicate via `duplicate_of_id` — the spec's
     * literal wording ("a manager-only action") is read here as this
     * codebase's usual hierarchy-inclusive shorthand (matching how
     * ExpenditurePolicy::viewDashboard() and every other tiered gate in
     * this app always includes the roles above the one named), not as an
     * exclusion of super/admin — there's no precedent anywhere else in this
     * codebase for a higher role being locked out of an action a lower one
     * can do.
     */
    public function linkDuplicate(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }

    /**
     * Assigning a complaint to a staff member ("I've got this" — purely
     * metadata, see Complaint's class doc). Same tier as linkDuplicate():
     * an office triage action, not open to every role the way create() is.
     */
    public function assign(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin', 'manager');
    }

    /**
     * The Level 3 human gate (references/complaint-desk.md section 3):
     * "a prominent 'Notify Investors' action becomes visible to
     * super/admin". Deliberately narrower than resolve()/reopen()/
     * linkDuplicate()/assign() — `manager` is excluded here, unlike every
     * other tiered gate in this feature, matching the spec's literal role
     * list for this one action. App\Services\ComplaintEscalationService::
     * notifyInvestors() separately enforces the 48h-armed business rule and
     * idempotency; this is authorization only.
     */
    public function notifyInvestors(User $user): bool
    {
        return $this->context->isAnyOf('super', 'admin');
    }
}
