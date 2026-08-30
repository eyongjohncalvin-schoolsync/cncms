<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ArrearsAdjustmentData;
use App\DataTransferObjects\RejectArrearsAdjustmentData;
use App\Jobs\RecalculateCustomerManuscriptsForwardJob;
use App\Models\Agent;
use App\Models\ArrearsAdjustment;
use App\Models\Company;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\User;
use App\Repositories\Contracts\ArrearsAdjustmentRepositoryInterface;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Arrears Adjustment core service — the maker-checker ledger correction
 * feature (synthesized ledger/approval-workflow/edge-case/UX-audit design
 * doc). Mirrors App\Services\ComplaintService's split of responsibility:
 * authorization is App\Policies\ArrearsAdjustmentPolicy's job (trusted to
 * have already run before create()/approve()/reject() are called); this
 * class owns the ledger-correctness rules — the two-approver gate, the
 * approval-time staleness re-check, and triggering the actual manuscript
 * recalculation once an adjustment is fully approved.
 */
class ArrearsAdjustmentService
{
    public function __construct(
        private readonly ArrearsAdjustmentRepositoryInterface $adjustments,
        private readonly CustomerRepositoryInterface $customers,
        private readonly CustomerManuscriptRecalculationService $recalculator,
        private readonly NotificationService $notifications,
        private readonly ManuscriptRunLockService $runLock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  See ArrearsAdjustmentRepositoryInterface::paginate().
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->adjustments->paginate($filters, $perPage);
    }

    public function findOrFail(string $uuid): ArrearsAdjustment
    {
        $adjustment = $this->adjustments->findByUuid($uuid, ['customer.zone', 'requestedBy', 'approvedBy', 'secondApprovedBy', 'complaint']);

        if (! $adjustment) {
            throw new ModelNotFoundException("Arrears adjustment [{$uuid}] not found.");
        }

        return $adjustment;
    }

    /**
     * @return Collection<int, ArrearsAdjustment>
     */
    public function recentForCustomer(Customer $customer, int $limit = 10): Collection
    {
        return $this->adjustments->recentForCustomer($customer->id, $limit);
    }

    /**
     * @return array{pending_approval: int, applied_this_month: int, total_written_off: string}
     */
    public function dashboard(): array
    {
        return $this->adjustments->dashboardCounts();
    }

    /**
     * Creates a new request, always landing at status = 'pending' regardless
     * of amount/reason/repeat-customer — the two-approver gate is evaluated
     * fresh at APPROVAL time (see requiresSecondApproval() below), never
     * baked in at request time, which is what makes the staleness re-check
     * meaningful rather than redundant with a decision already made here.
     *
     * `arrears_snapshot` captures the customer's current arrears figure for
     * $data->targetPeriod right now, purely so approve() can later detect
     * "this has drifted since the request was made" — see this feature's
     * migration doc comment.
     */
    public function create(ArrearsAdjustmentData $data, int $requestedByUserId): ArrearsAdjustment
    {
        $customer = $this->customers->findByUuid($data->customerUuid);

        if (! $customer) {
            throw ValidationException::withMessages(['customer_uuid' => ['The selected customer does not exist.']]);
        }

        $complaintId = null;

        if ($data->complaintUuid !== null) {
            $complaint = Complaint::query()->where('uuid', $data->complaintUuid)->first();

            if (! $complaint) {
                throw ValidationException::withMessages(['complaint_uuid' => ['The selected complaint does not exist.']]);
            }

            $complaintId = $complaint->id;
        }

        $adjustment = $this->adjustments->create($requestedByUserId, [
            ...$data->toAttributes(),
            'customer_id' => $customer->id,
            'complaint_id' => $complaintId,
            'arrears_snapshot' => $this->arrearsFor($customer, $data->targetPeriod),
            // Both snapshots are captured for every request (cheap, and keeps
            // the two staleness checks symmetric) — approve() only compares
            // the one that matches this adjustment's `target`.
            'credit_snapshot' => $this->creditFor($customer, $data->targetPeriod),
            'status' => 'pending',
        ]);

        return $adjustment->load(['customer.zone', 'requestedBy']);
    }

    /**
     * ArrearsAdjustmentPolicy::approve() has already checked the actor may
     * act at the CURRENT stage (role + not-the-requester, narrower again for
     * the second stage — see that Policy's class doc). This method:
     *
     *   1. Re-fetches the customer's CURRENT state and re-runs the staleness
     *      check — refuses to apply against numbers that have since drifted
     *      (mirrors App\Services\ManuscriptGenerationBatchService::publish()'s
     *      newer-published-run guard).
     *   2. Re-derives requiresSecondApproval() fresh (never trusts a value
     *      decided at request time).
     *   3. On a first approval that needs a second: records approved_by/
     *      approved_at, moves to 'pending_second_approval', and stops —
     *      ZERO ledger effect yet.
     *   4. On a first approval that does NOT need a second, or on a second
     *      approval: records the approval, moves to 'approved', and applies
     *      the ledger effect (see applyLedgerEffect()).
     *
     * @throws ValidationException if the adjustment is not currently pending
     *                             (either stage), or if the staleness
     *                             re-check trips.
     */
    public function approve(ArrearsAdjustment $adjustment, User $actor): ArrearsAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): ArrearsAdjustment {
            // Re-fetch under a row lock rather than trusting the caller's
            // in-memory copy: two requests that both loaded the same
            // 'pending' row before either committed a decision must not
            // both be able to approve it (or double-apply the ledger
            // effect). Checking isPending() on the object the caller
            // handed in would silently pass on a stale copy whose status
            // was still 'pending' in memory even though the DB row had
            // already moved on — see ArrearsAdjustmentTest's stale-read
            // guard test, which caught exactly this.
            $adjustment = ArrearsAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);

            if (! $adjustment->isPending()) {
                throw ValidationException::withMessages([
                    'status' => ['This adjustment has already been decided and cannot be approved again.'],
                ]);
            }

            $customer = Customer::query()->findOrFail($adjustment->customer_id);

            // Staleness re-check — on the dimension this adjustment targets.
            // A 'credit' adjustment is refused if the customer's credit for
            // target_period has drifted since the request (mirrors the
            // original arrears check); an 'arrears' adjustment keeps the
            // exact original behavior.
            if ($adjustment->targetsCredit()) {
                $currentCredit = $this->creditFor($customer, $adjustment->target_period);
                $snapshot = $adjustment->credit_snapshot === null ? null : (string) $adjustment->credit_snapshot;

                if ($snapshot === null || bccomp($currentCredit, $snapshot, 2) !== 0) {
                    throw ValidationException::withMessages([
                        'status' => [
                            "This customer's credit figure for {$adjustment->target_period} has changed since this ".
                            'request was made (was '.($snapshot ?? 'not captured')." FCFA, now {$currentCredit} FCFA). ".
                            'Reject this request and have a fresh one submitted against the current figure rather than applying it blindly.',
                        ],
                    ]);
                }
            } else {
                $currentArrears = $this->arrearsFor($customer, $adjustment->target_period);

                if (bccomp($currentArrears, (string) $adjustment->arrears_snapshot, 2) !== 0) {
                    throw ValidationException::withMessages([
                        'status' => [
                            "This customer's arrears figure for {$adjustment->target_period} has changed since this ".
                            "request was made (was {$adjustment->arrears_snapshot} FCFA, now {$currentArrears} FCFA). ".
                            'Reject this request and have a fresh one submitted against the current figure rather than applying it blindly.',
                        ],
                    ]);
                }
            }

            if ($adjustment->status === 'pending' && $this->requiresSecondApproval($adjustment)) {
                return $this->adjustments->update($adjustment, [
                    'approved_by' => $actor->id,
                    'approved_at' => Carbon::now(),
                    'status' => 'pending_second_approval',
                ]);
            }

            $adjustment = $adjustment->status === 'pending'
                ? $this->adjustments->update($adjustment, [
                    'approved_by' => $actor->id,
                    'approved_at' => Carbon::now(),
                    'status' => 'approved',
                ])
                : $this->adjustments->update($adjustment, [
                    'second_approved_by' => $actor->id,
                    'second_approved_at' => Carbon::now(),
                    'status' => 'approved',
                ]);

            $this->applyLedgerEffect($adjustment, $customer, $actor->id);
            $this->notifyApproved($adjustment);

            return $adjustment->fresh(['customer.zone', 'requestedBy', 'approvedBy', 'secondApprovedBy']);
        });
    }

    /**
     * Zero ledger effect, ever — the request record is left in place as a
     * permanent audit artifact (see App\Models\ArrearsAdjustment's class
     * doc). ArrearsAdjustmentPolicy::reject() gates who may call this at the
     * current stage; here it is only the data-shape/state rule.
     */
    public function reject(ArrearsAdjustment $adjustment, RejectArrearsAdjustmentData $data): ArrearsAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data): ArrearsAdjustment {
            // Same stale-read close as approve() above — re-fetch under a
            // row lock rather than trusting the caller's in-memory copy.
            $adjustment = ArrearsAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);

            if (! $adjustment->isPending()) {
                throw ValidationException::withMessages([
                    'status' => ['This adjustment has already been decided and cannot be rejected.'],
                ]);
            }

            $adjustment = $this->adjustments->update($adjustment, [
                'status' => 'rejected',
                'rejection_reason' => $data->rejectionReason,
            ]);

            $this->notifyRejected($adjustment);

            return $adjustment;
        });
    }

    /**
     * The two-approver threshold (this feature's design doc): amount over
     * the tenant-configurable threshold, OR this customer had any other
     * approved adjustment in the last 90 days, OR the reason category is
     * 'legacy_migration_error'.
     *
     * The 'legacy_migration_error' branch is a deliberate, conservative
     * judgment call: the design doc asks for this to be scoped to "a
     * customer whose record wasn't part of the original legacy import
     * cohort," but this schema has no reliable signal to distinguish that
     * cohort from any other customer (no `imported_at`/`is_legacy` flag —
     * `imported_by` on Customer is set by ANY bulk import, not specifically
     * the original migration, and customers.created_at is not a trustworthy
     * proxy either). Rather than guess at a fragile heuristic, every
     * 'legacy_migration_error' adjustment always requires the extra
     * scrutiny of a second, more senior approver — the safe default. A
     * follow-up (e.g. a real `customers.legacy_import_cohort` flag) could
     * narrow this later.
     */
    private function requiresSecondApproval(ArrearsAdjustment $adjustment): bool
    {
        $threshold = (string) (Company::cached()?->arrears_second_approval_threshold ?? ArrearsAdjustment::DEFAULT_SECOND_APPROVAL_THRESHOLD);

        if (bccomp((string) $adjustment->amount, $threshold, 2) > 0) {
            return true;
        }

        if ($adjustment->reason_category === 'legacy_migration_error') {
            return true;
        }

        return $this->adjustments->hasApprovedSince($adjustment->customer_id, Carbon::now()->subDays(90), $adjustment->id);
    }

    /**
     * The customer's total_arrears for $period if a manuscript row already
     * exists for it, else their most recent manuscript's total_arrears, else
     * '0.00' for a customer with no manuscript history at all yet. Shared by
     * create() (the request-time snapshot) and approve() (the approval-time
     * re-check) so the two can never disagree on what "the arrears figure"
     * means.
     */
    private function arrearsFor(Customer $customer, string $period): string
    {
        $manuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', $period)
            ->first();

        $manuscript ??= Manuscript::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('period')
            ->first();

        return $manuscript ? bcadd((string) $manuscript->total_arrears, '0.00', 2) : '0.00';
    }

    /**
     * The credit-side counterpart of arrearsFor() — the customer's `credit`
     * for $period (or their latest manuscript's, or '0.00'). Shared by
     * create() (the request-time `credit_snapshot`) and approve() (the
     * approval-time re-check for `target = 'credit'`) so the two can never
     * disagree on what "the credit figure" means.
     */
    private function creditFor(Customer $customer, string $period): string
    {
        $manuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', $period)
            ->first();

        $manuscript ??= Manuscript::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('period')
            ->first();

        return $manuscript ? bcadd((string) $manuscript->credit, '0.00', 2) : '0.00';
    }

    /**
     * The single most important correctness step: once an adjustment is
     * fully approved, its effect lands on the ledger by one of two routes,
     * chosen by the state of the `target_period` manuscript row:
     *
     *   - Normal case (no row yet, or a live row in the current/next
     *     period): a REAL ManuscriptCalculator run, never a direct write —
     *     see App\Services\CustomerManuscriptRecalculationService and
     *     App\Jobs\RecalculateCustomerManuscriptsForwardJob's class docs for
     *     the synchronous-current-period + queued-forward-sweep split.
     *   - Imported-baseline row (`command_run_id IS NULL`): a bounded,
     *     audited DELTA to that one row (applyDirectDelta()) — a from-scratch
     *     recompute of a v1-imported figure re-reads settled v1 history and
     *     corrupts it (the 2026-08 incident). See the branch below.
     *
     * $actorId (the approving admin — the second/only approver, per
     * approve()'s own doc comment) is threaded through both recalculation
     * paths purely for audit attribution
     * (CustomerManuscriptRecalculationService::recalculateOne()'s new
     * command_runs metadata — see that class's doc comment): the
     * synchronous call here still has a real auth() context to fall back on,
     * but RecalculateCustomerManuscriptsForwardJob runs on a queue worker,
     * where auth()->id() is already gone by the time handle() executes — so
     * $actorId is captured HERE, while it's still available, and carried on
     * the job itself rather than re-derived (impossible) later.
     */
    private function applyLedgerEffect(ArrearsAdjustment $adjustment, Customer $customer, int $actorId): void
    {
        $targetPeriod = $adjustment->target_period;

        $targetManuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', $targetPeriod)
            ->first();

        // --- Delta-vs-recalc branch (2026-08-30, the credit-correction
        // addendum in this feature's design doc) --------------------------
        //
        // The 2026-08 `swecom` incident: an approved adjustment against an
        // IMPORTED-BASELINE manuscript row (`command_run_id IS NULL` — a v1
        // register figure written verbatim, with no v2 payment-processing
        // history behind it) went through the normal recalc path below.
        // recalculateOne() re-derived that period from scratch —
        // `net = previousNet + (bill - income) ± adjustment`, where `income`
        // is the sum of every not-yet-consumed verified payment — so ~42,000
        // FCFA of historical v1 payments were re-counted as fresh August
        // income, `net` went hugely negative, and a bogus `credit` of ~40,000
        // was fabricated. The correct baseline `credit` was 0.
        //
        // Rule: a ledger correction must NOT trigger a from-scratch recompute
        // of a period whose manuscript row is an imported baseline or whose
        // period has closed. Instead:
        //
        //   1. Imported baseline (`command_run_id IS NULL`, row present):
        //      apply a bounded, audited DELTA to that one row — through an
        //      Eloquent save so the Auditable trait records old/new values —
        //      and do NOT call recalculateOne() or dispatch the forward job.
        //   2. Closed period whose row WAS finalised by a real
        //      manuscript:calculate run: refuse the approval with a clear
        //      error (immutability — a published, elapsed period is never
        //      rewritten in place).
        //   3. Everything else (no row yet, or a live row in the current /
        //      next period): the original recalc path, unchanged.
        if ($targetManuscript !== null
            && $targetManuscript->command_run_id !== null
            && $this->runLock->isPeriodLocked($targetPeriod)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Billing period {$targetPeriod} is closed and its manuscript was finalised by a published ".
                    'calculation run — it cannot be corrected automatically. Have the owner apply a manual, '.
                    'audited ledger fix for this period instead.',
                ],
            ]);
        }

        if ($targetManuscript !== null && $targetManuscript->command_run_id === null) {
            // No $actorId needed here: this runs inside approve()'s web
            // request, so App\Observers\AuditableObserver already attributes
            // the manuscripts + adjustment writes to the authenticated
            // approver via auth()->id().
            $this->applyDirectDelta($adjustment, $targetManuscript);

            return;
        }

        // --- Normal path (unchanged) ------------------------------------
        $currentPeriod = Carbon::now()->format('Y-m');

        $this->recalculator->recalculateOne(
            $customer,
            $currentPeriod,
            trigger: 'arrears_adjustment',
            auditContext: ['arrears_adjustment_id' => $adjustment->id, 'triggered_by_user_id' => $actorId],
        );

        RecalculateCustomerManuscriptsForwardJob::dispatch($customer->id, $adjustment->target_period, $adjustment->id, $actorId);
    }

    /**
     * Applies an approved adjustment as a bounded, audited delta to a single
     * imported-baseline `manuscripts` row — never a from-scratch recompute
     * (see applyLedgerEffect()'s doc for why). The write goes through the
     * Eloquent model so App\Traits\Auditable records the before/after
     * `manuscripts` values, and the adjustment is stamped processed so a
     * later run for the same period treats it as already consumed.
     *
     * Direction semantics (identical sign convention to ManuscriptCalculator,
     * `net = arrears - credit`):
     *   - target 'arrears': 'decrease' → total_arrears -= amount (clamped 0);
     *                       'increase' → total_arrears += amount.
     *   - target 'credit':  'increase' → credit -= amount (clamped 0) — a
     *                       claw-back of credit that should not exist;
     *                       'decrease' → credit += amount — granting credit.
     * total_bill is kept internally consistent as
     * `max(0, bill + total_arrears - credit)`.
     */
    private function applyDirectDelta(ArrearsAdjustment $adjustment, Manuscript $manuscript): void
    {
        $amount = bcadd((string) $adjustment->amount, '0.00', 2);
        $arrears = bcadd((string) $manuscript->total_arrears, '0.00', 2);
        $credit = bcadd((string) $manuscript->credit, '0.00', 2);
        $bill = bcadd((string) $manuscript->bill, '0.00', 2);

        if ($adjustment->targetsCredit()) {
            $credit = $adjustment->direction === 'increase'
                ? $this->clampZero(bcsub($credit, $amount, 2))
                : bcadd($credit, $amount, 2);
        } else {
            $arrears = $adjustment->direction === 'decrease'
                ? $this->clampZero(bcsub($arrears, $amount, 2))
                : bcadd($arrears, $amount, 2);
        }

        $totalBill = $this->clampZero(bcsub(bcadd($bill, $arrears, 2), $credit, 2));

        // Eloquent update → Auditable `updated` observer → audit_logs row
        // with old/new values. command_run_id stays NULL (still an imported
        // baseline, now with one audited correction on top).
        $manuscript->update([
            'total_arrears' => $arrears,
            'credit' => $credit,
            'total_bill' => $totalBill,
        ]);

        $adjustment->forceFill([
            'processed_at' => Carbon::now(),
            'processed_period' => $adjustment->target_period,
        ])->save();
    }

    private function clampZero(string $value): string
    {
        return bccomp($value, '0.00', 2) < 0 ? '0.00' : bcadd($value, '0.00', 2);
    }

    private function notifyApproved(ArrearsAdjustment $adjustment): void
    {
        $adjustment->loadMissing(['customer.zone', 'requestedBy']);
        $customer = $adjustment->customer;

        if ($adjustment->requestedBy) {
            $this->notifications->broadcastToUser(
                $adjustment->requestedBy,
                'arrears_adjustment.approved',
                'info',
                'Arrears adjustment approved',
                "Your arrears adjustment request for {$customer->name} ({$adjustment->target_period}) has been approved and applied.",
                "/customers/{$customer->uuid}",
                'arrears_adjustment',
                $adjustment->uuid,
            );
        }

        if ($customer->zone_id === null) {
            return;
        }

        Agent::query()
            ->where('zone_id', $customer->zone_id)
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->each(function (Agent $agent) use ($adjustment, $customer): void {
                if (! $agent->user) {
                    return;
                }

                $this->notifications->broadcastToUser(
                    $agent->user,
                    'arrears_adjustment.approved',
                    'info',
                    'Customer balance adjusted',
                    "{$customer->name}'s arrears balance was adjusted for {$adjustment->target_period} — check the current balance before your next visit.",
                    "/customers/{$customer->uuid}",
                    'arrears_adjustment',
                    $adjustment->uuid,
                );
            });
    }

    private function notifyRejected(ArrearsAdjustment $adjustment): void
    {
        $adjustment->loadMissing(['customer', 'requestedBy']);

        if (! $adjustment->requestedBy) {
            return;
        }

        $this->notifications->broadcastToUser(
            $adjustment->requestedBy,
            'arrears_adjustment.rejected',
            'info',
            'Arrears adjustment rejected',
            "Your arrears adjustment request for {$adjustment->customer->name} ({$adjustment->target_period}) was rejected: {$adjustment->rejection_reason}",
            "/customers/{$adjustment->customer->uuid}",
            'arrears_adjustment',
            $adjustment->uuid,
        );
    }
}
