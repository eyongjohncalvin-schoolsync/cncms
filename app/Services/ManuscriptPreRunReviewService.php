<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The pre-run "who hasn't paid" review list (UX deliberation pass, this
 * cycle): a read-only, on-demand summary an admin can pull up BEFORE
 * clicking "Run Manuscript Calculation" for a period, surfacing which
 * currently-active customers are about to accrue a full period's arrears
 * with NOTHING covering it — no verified payment, no live prepaid window,
 * no leftover credit — so a data-entry gap (a payment that was recorded but
 * never verified, say) can be caught before the run locks a period's numbers
 * in, rather than discovered afterward.
 *
 * Deliberately NOT the same feature as, and does NOT share cached results
 * with, App\Services\CustomerEligibilityService's arrears-based
 * disconnection-eligibility board — that one asks "has this customer's
 * ACCUMULATED PRIOR arrears crossed 3x their bill" (a historical-balance
 * question, evaluated only past the payment deadline); this one asks "does
 * ANYTHING cover the UPCOMING period P specifically, right now" (a
 * forward-looking, always-available question). Both happen to be built on
 * App\Repositories\Contracts\CustomerRepositoryInterface::
 * activeWithLatestManuscript() since both need "every active customer plus
 * their latest manuscript", and both share the same "fully computed at
 * request time, not a persisted flag" reasoning (see that class's doc
 * comment for why) — but the filtering logic and the questions they answer
 * are unrelated. Do not conflate them or let one's result serve the other.
 *
 * Flags active customer C for period P when ALL of:
 *   1. customers.status = 'active'.
 *   2. No eligible verified payment for P — App\Models\Payment::
 *      scopeEligibleForPeriod(), the SAME predicate
 *      App\Support\ScheduledTasks\ManuscriptChunkDataResolver and
 *      App\Console\Commands\ManuscriptCalculate use to decide "eligible
 *      income for this period" when a real run actually executes. This is
 *      that scope's third caller, not a new, independently-drifting
 *      definition of "hasn't paid" (deliberately NOT `payments.created_at`
 *      falling in the current calendar month, which would disagree with
 *      what a real run actually consumes).
 *   3. NOT excluded by an active prepaid window — C's latest manuscript's
 *      `payment_expiration` is null or not in the future.
 *   4. NOT excluded by credit already covering the bill — C's latest
 *      manuscript's `credit` >= `customers.bill` (the CURRENT rate, not
 *      whatever `bill` the manuscript itself last recorded).
 *
 * A customer with no manuscript history at all yet (first-ever period) has
 * no prepaid window or credit to exclude them by definition, so rules 3/4
 * simply never apply — such a customer is flagged whenever rule 2 alone
 * flags them, which is the correct behavior: nothing yet covers their first
 * bill either.
 */
final class ManuscriptPreRunReviewService
{
    private const SCALE = 2;

    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    /**
     * @return array{summary: array{count: int, total_exposure: string}, customers: array<int, array<string, mixed>>}
     */
    public function reviewList(string $period, ?int $zoneId = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $candidates = $this->customers->activeWithLatestManuscript($zoneId);
        $customerIds = $candidates->pluck('id')->all();

        $customerIdsWithEligiblePayment = Payment::query()
            ->whereIn('customer_id', $customerIds)
            ->eligibleForPeriod($period)
            ->pluck('customer_id')
            ->unique()
            ->flip();

        $lastPaymentDateByCustomer = $this->lastPaymentDates($customerIds);

        $flagged = $candidates
            ->reject(fn (Customer $customer): bool => $customerIdsWithEligiblePayment->has($customer->id))
            ->reject(fn (Customer $customer): bool => $this->coveredByPrepaidWindow($customer, $asOf))
            ->reject(fn (Customer $customer): bool => $this->coveredByCredit($customer))
            ->values();

        return [
            'summary' => [
                'count' => $flagged->count(),
                'total_exposure' => $flagged->reduce(
                    fn (string $carry, Customer $customer): string => bcadd($carry, $this->normalize((string) $customer->bill), self::SCALE),
                    '0.00'
                ),
            ],
            'customers' => $flagged
                ->map(fn (Customer $customer): array => $this->shape($customer, $lastPaymentDateByCustomer->get($customer->id)))
                ->values()
                ->all(),
        ];
    }

    /**
     * Rule 3: excluded when the customer is inside a prepaid window — either
     * the draw-down counter (references/prepayment-drawdown.md:
     * prepaid_months_remaining > 0) OR the legacy calendar freeze
     * (payment_expiration set and still future as of $asOf). Null / elapsed
     * / zero on both excludes nobody.
     */
    private function coveredByPrepaidWindow(Customer $customer, Carbon $asOf): bool
    {
        $manuscript = $customer->latestManuscript;

        if ($manuscript === null) {
            return false;
        }

        if ((int) $manuscript->prepaid_months_remaining > 0) {
            return true;
        }

        $expiration = $manuscript->payment_expiration;

        return $expiration !== null && Carbon::parse($expiration)->greaterThan($asOf);
    }

    /**
     * Rule 4: credit >= customers.bill (the current rate) — bccomp, never
     * float, matching every other money comparison in this codebase
     * (ManuscriptCalculator, CustomerEligibilityService).
     */
    private function coveredByCredit(Customer $customer): bool
    {
        $manuscript = $customer->latestManuscript;

        if ($manuscript === null) {
            return false;
        }

        $credit = $this->normalize((string) $manuscript->credit);
        $bill = $this->normalize((string) $customer->bill);

        return bccomp($credit, $bill, self::SCALE) >= 0;
    }

    /**
     * Batch-resolved once for every candidate (not per-customer) — same
     * "no N+1" discipline as App\Support\ScheduledTasks\ManuscriptChunkDataResolver.
     * Deliberately the most recent payment of ANY verification status
     * (mirrors CustomerController::lastPayment()'s existing
     * `latest('created_at')` convention) — purely informational context for
     * the reviewer ("when did this customer last pay anything"), not part of
     * the eligibility predicate above.
     *
     * @param  array<int, int>  $customerIds
     * @return Collection<int, string>
     */
    private function lastPaymentDates(array $customerIds): Collection
    {
        if ($customerIds === []) {
            return new Collection;
        }

        return Payment::query()
            ->whereIn('customer_id', $customerIds)
            ->selectRaw('customer_id, MAX(created_at) as last_payment_at')
            ->groupBy('customer_id')
            ->pluck('last_payment_at', 'customer_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Customer $customer, ?string $lastPaymentAt): array
    {
        return [
            'uuid' => $customer->uuid,
            'name' => $customer->name,
            'zone_uuid' => $customer->zone?->uuid,
            'zone_name' => $customer->zone?->name,
            'phone' => $customer->phone,
            'bill' => $this->normalize((string) $customer->bill),
            'last_payment_date' => $lastPaymentAt !== null ? Carbon::parse($lastPaymentAt)->toDateString() : null,
        ];
    }

    private function normalize(string $value): string
    {
        return bcadd($value, '0.00', self::SCALE);
    }
}
