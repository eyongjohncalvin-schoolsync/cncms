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
     *                              (either stage), or if the staleness
     *                              re-check trips.
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
     * The single most important correctness step: once an adjustment is
     * fully approved, its effect only actually lands on the ledger via a
     * REAL ManuscriptCalculator run, never a direct write to `manuscripts` —
     * see App\Services\CustomerManuscriptRecalculationService and
     * App\Jobs\RecalculateCustomerManuscriptsForwardJob's class docs for the
     * synchronous-current-period + queued-forward-sweep split.
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
        $currentPeriod = Carbon::now()->format('Y-m');

        $this->recalculator->recalculateOne(
            $customer,
            $currentPeriod,
            trigger: 'arrears_adjustment',
            auditContext: ['arrears_adjustment_id' => $adjustment->id, 'triggered_by_user_id' => $actorId],
        );

        RecalculateCustomerManuscriptsForwardJob::dispatch($customer->id, $adjustment->target_period, $adjustment->id, $actorId);
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
