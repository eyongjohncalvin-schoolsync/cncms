<?php

declare(strict_types=1);

namespace App\Support\ScheduledTasks;

use App\Models\ArrearsAdjustment;
use App\Models\Manuscript;
use App\Models\Payment;
use Illuminate\Support\Collection;

/**
 * Batch-resolves, once per chunk, the three lookups
 * App\Services\ManuscriptCalculator needs per customer: each customer's most
 * recent prior-period manuscript, their period-eligible verified payments,
 * and their period-eligible approved arrears adjustments. Extracted out of
 * App\Jobs\ComputeManuscriptChunkJob's handle() (rather than inlined like
 * App\Console\Commands\ManuscriptCalculate::runForEveryCustomer does) for
 * exactly one reason: it runs OUTSIDE the job's per-customer try/catch, so a
 * failure here (e.g. a transient DB error affecting this chunk's bulk query)
 * fails the whole chunk job — the coarser, batch-level failure mode
 * task-scheduler.md section 4.1 distinguishes from a single bad customer
 * record inside an otherwise-successful chunk. Being its own class also
 * makes that specific failure mode independently mockable in tests
 * (see tests/Feature/Api/ManuscriptSchedulerTest.php) without needing to
 * fake a lower-level DB failure.
 *
 * IMPORTANT: this is one of exactly two places that resolve arrears
 * adjustment eligibility (the other is
 * App\Console\Commands\ManuscriptCalculate::runForEveryCustomer(), which
 * inlines the identical query for its own non-chunked/non-queued run) — see
 * App\Services\ManuscriptCalculator's class doc for what "eligible" means.
 * Both must stay in lockstep; a change to one without the other silently
 * makes the manual/scheduled paths disagree.
 *
 * Payment eligibility (as opposed to adjustment eligibility, above) no
 * longer has this problem: it now goes through the single shared
 * App\Models\Payment::scopeEligibleForPeriod() predicate — see that
 * method's doc comment — rather than being inlined here a second time.
 */
class ManuscriptChunkDataResolver
{
    /**
     * @param  array<int, int>  $customerIds
     * @return array{0: Collection<int, Manuscript>, 1: Collection<int, Collection<int, Payment>>, 2: Collection<int, Collection<int, ArrearsAdjustment>>}
     */
    public function resolve(array $customerIds, string $period, bool $withPreviousManuscripts = true): array
    {
        $previousManuscriptsByCustomer = $withPreviousManuscripts
            ? self::latestBefore($customerIds, $period)
            : new Collection;

        // Eligibility for period $period — see Payment::scopeEligibleForPeriod()'s
        // doc comment for the full rationale; this is one of that scope's
        // three callers, not an inlined copy of its predicate.
        $eligibleVerifiedPaymentsByCustomer = Payment::query()
            ->whereIn('customer_id', $customerIds)
            ->eligibleForPeriod($period)
            ->get()
            ->groupBy('customer_id');

        // Same idempotency mechanism, applied to approved arrears
        // adjustments targeting exactly this period.
        $eligibleAdjustmentsByCustomer = ArrearsAdjustment::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'approved')
            ->where('target_period', $period)
            ->where(fn ($query) => $query->whereNull('processed_period')->orWhere('processed_period', $period))
            ->get()
            ->groupBy('customer_id');

        return [$previousManuscriptsByCustomer, $eligibleVerifiedPaymentsByCustomer, $eligibleAdjustmentsByCustomer];
    }

    /**
     * Each customer's latest manuscript before $period — one row per
     * customer (DISTINCT ON, served by the customer/period unique index),
     * not their whole history.
     *
     * @param  array<int, int>  $customerIds
     * @return Collection<int, Manuscript>
     */
    public static function latestBefore(array $customerIds, string $period): Collection
    {
        return Manuscript::query()
            ->selectRaw('distinct on (manuscripts.customer_id) manuscripts.*')
            ->whereIn('customer_id', $customerIds)
            ->where('period', '<', $period)
            ->orderBy('customer_id')
            ->orderByDesc('period')
            ->get()
            ->keyBy('customer_id')
            ->toBase();
    }
}
