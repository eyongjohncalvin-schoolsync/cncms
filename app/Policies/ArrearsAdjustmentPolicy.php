<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ArrearsAdjustment;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Arrears Adjustment maker-checker permissions — mirrors
 * App\Policies\ComplaintPolicy's structural shape exactly (per this
 * feature's design doc: "approved_by !== requested_by enforced in the
 * Policy method itself, mirror ComplaintPolicy::resolve() exactly").
 *
 * create() is deliberately ungated beyond "is an authenticated tenant
 * user" — all 5 roles may request an adjustment, same as
 * ComplaintPolicy::create().
 *
 * approve()/reject() are STATE-DEPENDENT, not a flat role check: which
 * gate applies depends on $adjustment->status, because this is a two-stage
 * approval chain, not a single approve action —
 *
 *   - status = 'pending' (first approval/rejection): super/admin/manager,
 *     and the actor must not be the original requester.
 *   - status = 'pending_second_approval' (second approval/rejection):
 *     narrower — admin/super only — and the actor must differ from BOTH
 *     the requester AND whoever gave the first approval.
 *   - any other status (already 'approved'/'rejected'): false — there is
 *     nothing left to approve or reject.
 *
 * SUPER SELF-APPROVAL CARVE-OUT (2026-08-29): the `super` role — the single
 * business owner in this ~6-person, owner-operated company — is exempt from
 * the maker≠checker rule at BOTH stages: a super may approve/reject an
 * adjustment they themselves raised (and, at the second stage, one they also
 * gave the first approval to). Rationale: the owner is the ultimate
 * authority and must not be permanently deadlocked on their own small ledger
 * corrections when no other senior reviewer is available. `admin` and
 * `manager` stay fully bound by maker≠checker and the two-senior-approver
 * identity rules exactly as before — the carve-out is `super`-only and
 * deliberately hardcoded (no config flag; configurable permissions are a
 * separate, later effort). The web review UI surfaces a confirmation step
 * for a super acting on their own request so the bypass is explicit and, as
 * always, it lands in the audit log.
 *
 * This is intentionally the single source of truth for "who may act on
 * this adjustment right now" — App\Services\ArrearsAdjustmentService trusts
 * that this check already ran (same "authorization is the Policy's job,
 * not the Service's" split as every other Service in this app) and only
 * re-derives the SECOND-APPROVAL-REQUIRED business rule (amount threshold /
 * 90-day repeat / reason category) at approval time, which is a different
 * question from "is this actor allowed to act at this stage."
 */
class ArrearsAdjustmentPolicy
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

    public function approve(User $user, ArrearsAdjustment $adjustment): bool
    {
        return match ($adjustment->status) {
            'pending' => $this->context->isAnyOf('super', 'admin', 'manager')
                && ($user->id !== $adjustment->requested_by || $this->context->is('super')),
            'pending_second_approval' => $this->context->isAnyOf('super', 'admin')
                && ($this->context->is('super')
                    || ($user->id !== $adjustment->requested_by
                        && $user->id !== $adjustment->approved_by)),
            default => false,
        };
    }

    /**
     * Whoever may approve at the current stage may also reject at that same
     * stage — a symmetric maker-checker veto, not a separately-tuned gate.
     */
    public function reject(User $user, ArrearsAdjustment $adjustment): bool
    {
        return $this->approve($user, $adjustment);
    }
}
