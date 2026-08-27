<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Customer;
use App\Services\CustomerManuscriptRecalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The forward re-run behind approving a PAST-period arrears adjustment (this
 * feature's design doc): recomputes ONE customer's manuscript for every
 * period from `$fromPeriod` through the current period, ascending, reusing
 * App\Services\CustomerManuscriptRecalculationService — i.e. the exact same
 * (idempotent) ManuscriptCalculator every other run in this app uses, never
 * a separate delta-propagation formula. Periods are processed sequentially,
 * each one's calculation depending on the previous period's freshly-written
 * manuscript row, so this is a single chained job (task-scheduler.md
 * section 4.1's "Chain is for strictly sequential jobs" case), not a
 * Bus::batch() of independent per-customer chunks like
 * App\Services\ManuscriptGenerationBatchService — there is only one
 * customer here, and the work is only "genuinely O(periods-since-target)"
 * (per the design doc), not O(customers).
 *
 * Queued (ShouldQueue) rather than run inline in the approval request, since
 * a target_period many months in the past means many sequential period
 * recalculations — real, if usually small, work that must not block the
 * HTTP response. App\Services\ArrearsAdjustmentService::approve() ALSO
 * triggers a synchronous, single-period recalculation of the CURRENT period
 * before dispatching this job, so the customer's live balance is correct
 * immediately rather than only after this job eventually runs — this job
 * still recomputes the current period too (redundantly, but harmlessly —
 * see ManuscriptCalculator's idempotency guarantee) as the last step of its
 * ascending sweep, so every intermediate period's manuscript row is also
 * corrected, not just the endpoints.
 *
 * Always dispatched from inside an active tenancy()->initialize() context
 * (ArrearsAdjustmentService::approve() runs under the web request's already-
 * resolved tenant) — Stancl's QueueTenancyBootstrapper transparently
 * re-initializes the correct tenant schema when this job actually runs on
 * the queue worker, exactly like App\Jobs\ComputeManuscriptChunkJob.
 */
class RecalculateCustomerManuscriptsForwardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $customerId,
        public readonly string $fromPeriod,
    ) {}

    public function handle(CustomerManuscriptRecalculationService $recalculator): void
    {
        $customer = Customer::query()->find($this->customerId);

        if (! $customer) {
            // Deleted between dispatch and this job actually running —
            // nothing left to recalculate.
            return;
        }

        $currentPeriod = Carbon::now()->format('Y-m');

        $period = Carbon::createFromFormat('Y-m-d', $this->fromPeriod.'-01');
        $current = Carbon::createFromFormat('Y-m-d', $currentPeriod.'-01');

        while ($period->lessThanOrEqualTo($current)) {
            $periodString = $period->format('Y-m');

            DB::transaction(function () use ($recalculator, $customer, $periodString): void {
                $recalculator->recalculateOne($customer, $periodString);
            });

            $period = $period->addMonthNoOverflow();
        }
    }
}
