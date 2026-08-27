<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Support\ScheduledTasks\ManuscriptChunkDataResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A single-customer, single-period ManuscriptCalculator invocation entry
 * point — the "thin wrapper, not new calculation logic" the Arrears
 * Adjustment feature's design doc calls for, since no such entry point
 * existed anywhere in this codebase before it (confirmed: every existing
 * caller of ManuscriptCalculator::calculate() processes a full customer set
 * — App\Console\Commands\ManuscriptCalculate::runForEveryCustomer() and
 * App\Jobs\ComputeManuscriptChunkJob — there was no way to recompute just
 * ONE customer's manuscript for ONE period on demand).
 *
 * Reuses App\Support\ScheduledTasks\ManuscriptChunkDataResolver (passing a
 * single-element customer id array) rather than duplicating its eligibility
 * queries a third time, so this stays in lockstep with the manual/scheduled
 * paths automatically.
 *
 * Writes DIRECTLY to `manuscripts` (no compute/publish preview gate — see
 * task-scheduler.md section 4's "this preview/publish gate applies to the
 * scheduled path only" rule) — this is the same "immediate compute+commit"
 * behavior as the manual manuscript:calculate trigger, applied to one
 * customer instead of the whole tenant. Used by:
 *
 *   - App\Services\ArrearsAdjustmentService::approve() for the CURRENT
 *     period's synchronous, read-time-immediate recalculation.
 *   - App\Jobs\RecalculateCustomerManuscriptsForwardJob for each period in
 *     the queued forward ripple from an approved past-period adjustment's
 *     target_period through the current period.
 */
class CustomerManuscriptRecalculationService
{
    public function __construct(
        private readonly ManuscriptCalculator $calculator,
        private readonly ManuscriptChunkDataResolver $resolver,
        private readonly ManuscriptService $manuscripts,
    ) {}

    public function recalculateOne(Customer $customer, string $period, ?Carbon $asOf = null): Manuscript
    {
        $previousManuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->first();

        [, $eligibleVerifiedPaymentsByCustomer, $eligibleAdjustmentsByCustomer] = $this->resolver->resolve([$customer->id], $period);

        $result = $this->calculator->calculate(
            $customer,
            $period,
            $previousManuscript,
            $eligibleVerifiedPaymentsByCustomer->get($customer->id, new Collection),
            $eligibleAdjustmentsByCustomer->get($customer->id, new Collection),
            $asOf,
        );

        $manuscript = Manuscript::query()
            ->firstOrNew(['customer_id' => $customer->id, 'period' => $period])
            ->fill($result->toManuscriptAttributes());
        $manuscript->save();

        foreach ($result->processedPayments as $payment) {
            $payment->forceFill(['processed_at' => Carbon::now(), 'processed_period' => $period])->save();
        }

        foreach ($result->processedAdjustments as $adjustment) {
            $adjustment->forceFill(['processed_at' => Carbon::now(), 'processed_period' => $period])->save();
        }

        $this->manuscripts->forgetSummaryCache($period);
        // Must match CustomerService::findOrFail()'s exact key format
        // (including the :branchId suffix) — a bare "customers:show:{uuid}"
        // forget() here was a silent no-op against the real, branch-suffixed
        // key, so a recalculation's effect on the customer detail page could
        // sit stale for up to that cache's 60s TTL. Not the customer's own
        // branch necessarily (this recalculation can be triggered by staff
        // in a different branch context than the one that last cached the
        // page), so this only reliably covers the unscoped 'all' variant —
        // a real branch-scoped cache entry still expires on its own 60s TTL.
        Cache::forget('customers:show:'.$customer->uuid.':all');

        return $manuscript;
    }
}
