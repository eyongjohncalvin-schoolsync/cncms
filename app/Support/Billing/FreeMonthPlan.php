<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Customer;
use App\Models\FreeMonthGrant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a customer is charged in a given month once free months, months paid
 * upfront per service and the installation month are taken into account
 * (docs/plans/installation-and-promotions.md, phase 2 + revision D15–D20) —
 * the one place the billing engine (App\Services\ManuscriptCalculator) and
 * every screen that previews a month (add-customer form, coverage line,
 * pre-run review, compensation start) ask "which services are charged this
 * month".
 *
 * Free months are stateless: they run BY CALENDAR from each approved grant's
 * start_period (D5), so the answer for any month is a pure function of the
 * approved grants, the per-service prices and `customers.billing_starts_period`
 * (the installation month — nothing is charged before it). Months paid
 * upfront per service are counters carried on the manuscript
 * (manuscripts.service_prepaid) and passed in by the caller; a month the
 * service is free uses none of them (D15/D6), so N paid months always cover
 * N billed months of that service.
 *
 * Once a service's free and paid months end it is billed exactly like every
 * other customer — same ledger, same pay-ahead rule (D18). The free part is
 * zero-value: freeValue() is reporting only and never enters the ledger.
 */
final readonly class FreeMonthPlan
{
    private const int SCALE = 2;

    /**
     * The "billing start" of a field registration awaiting activation
     * (installation phase 3): every month is before it, so the engine
     * reads the customer as not started: nothing charged, payments wait.
     */
    public const string NOT_ACTIVATED = '9999-12';

    /**
     * @param  array<int, string>  $servicePrices  service id => what that service (its base row plus
     *                                             any options) costs the customer each month
     * @param  list<array{service_id: ?int, start: string, end: string}>  $windows  approved grants
     */
    public function __construct(
        public ?string $billingStartsPeriod = null,
        public array $servicePrices = [],
        public array $windows = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function forCustomer(Customer $customer): self
    {
        return self::forCustomers(new Collection([$customer]))[$customer->id] ?? self::none();
    }

    /**
     * Batch-resolves every customer's plan with two queries (approved grants,
     * per-service prices) — the manuscript run calls this once per chunk.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<int, self> customer id => plan
     */
    public static function forCustomers(Collection $customers): array
    {
        $customerIds = $customers->pluck('id')->filter()->values()->all();

        if ($customerIds === []) {
            return [];
        }

        $grantsByCustomer = FreeMonthGrant::query()
            ->active()
            ->whereIn('customer_id', $customerIds)
            ->get(['customer_id', 'service_id', 'start_period', 'months', 'status'])
            ->groupBy('customer_id');

        $pricesByCustomer = DB::table('customer_service')
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id', 'service_id')
            ->selectRaw('customer_id, service_id, sum(price) as price')
            ->get()
            ->groupBy('customer_id');

        $plans = [];

        foreach ($customers as $customer) {
            $plans[$customer->id] = new self(
                billingStartsPeriod: $customer->isPendingActivation() ? self::NOT_ACTIVATED : $customer->billing_starts_period,
                servicePrices: $pricesByCustomer->get($customer->id, new Collection)
                    ->mapWithKeys(fn (object $row): array => [(int) $row->service_id => bcadd((string) $row->price, '0', self::SCALE)])
                    ->all(),
                windows: $grantsByCustomer->get($customer->id, new Collection)
                    ->map(fn (FreeMonthGrant $grant): array => [
                        'service_id' => $grant->service_id !== null ? (int) $grant->service_id : null,
                        'start' => (string) $grant->start_period,
                        'end' => (string) $grant->endPeriod(),
                    ])
                    ->values()
                    ->all(),
            );
        }

        return $plans;
    }

    /** True when no free months and no installation month affect billing. */
    public function isEmpty(): bool
    {
        return $this->billingStartsPeriod === null && $this->windows === [];
    }

    public function beforeBillingStarts(string $period): bool
    {
        return $this->billingStartsPeriod !== null && $period < $this->billingStartsPeriod;
    }

    /** Every service is free this month (an all-services grant covers it). */
    public function allServicesFree(string $period): bool
    {
        foreach ($this->windows as $window) {
            if ($window['service_id'] === null && $window['start'] <= $period && $period <= $window['end']) {
                return true;
            }
        }

        return false;
    }

    public function isServiceFree(int $serviceId, string $period): bool
    {
        if ($this->allServicesFree($period)) {
            return true;
        }

        foreach ($this->windows as $window) {
            if ($window['service_id'] === $serviceId && $window['start'] <= $period && $period <= $window['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ids of the services free this month (an all-services grant is reported
     * by allServicesFree() instead).
     *
     * @return list<int>
     */
    public function freeServiceIds(string $period): array
    {
        $ids = [];

        foreach ($this->windows as $window) {
            if ($window['service_id'] !== null && $window['start'] <= $period && $period <= $window['end']) {
                $ids[$window['service_id']] = $window['service_id'];
            }
        }

        return array_values($ids);
    }

    /**
     * What the free services would have cost this month — foregone revenue
     * for reports, never part of the ledger. Zero before billing starts.
     */
    public function freeValue(string $period, string $fullBill): string
    {
        if ($this->beforeBillingStarts($period)) {
            return '0.00';
        }

        $fullBill = bcadd($fullBill, '0', self::SCALE);

        if ($this->allServicesFree($period)) {
            return $fullBill;
        }

        $free = '0.00';

        foreach ($this->freeServiceIds($period) as $serviceId) {
            $free = bcadd($free, $this->servicePrices[$serviceId] ?? '0.00', self::SCALE);
        }

        return bccomp($free, $fullBill, self::SCALE) > 0 ? $fullBill : $free;
    }

    /**
     * What is charged this month: the bill less its free services (D7) and
     * less the services covered this month by months paid upfront
     * ($coveredServiceIds, D15).
     *
     * @param  list<int>  $coveredServiceIds
     */
    public function charge(string $period, string $fullBill, array $coveredServiceIds = []): string
    {
        if ($this->beforeBillingStarts($period)) {
            return '0.00';
        }

        $charge = bcsub(bcadd($fullBill, '0', self::SCALE), $this->freeValue($period, $fullBill), self::SCALE);

        foreach ($coveredServiceIds as $serviceId) {
            if (! $this->isServiceFree($serviceId, $period)) {
                $charge = bcsub($charge, $this->servicePrices[$serviceId] ?? '0.00', self::SCALE);
            }
        }

        return bccomp($charge, '0', self::SCALE) < 0 ? '0.00' : $charge;
    }

    /** Nothing charged this month because of free months (not merely before billing starts). */
    public function isFullyFree(string $period, string $fullBill): bool
    {
        return ! $this->beforeBillingStarts($period)
            && bccomp($this->freeValue($period, $fullBill), '0', self::SCALE) > 0
            && bccomp($this->charge($period, $fullBill), '0', self::SCALE) === 0;
    }

    /** Some services free, some charged. */
    public function isPartlyFree(string $period, string $fullBill): bool
    {
        return bccomp($this->freeValue($period, $fullBill), '0', self::SCALE) > 0
            && bccomp($this->charge($period, $fullBill), '0', self::SCALE) > 0;
    }

    /**
     * How many charged months a whole-bill advance (Record Payment, months /
     * yearly) buys from $fromPeriod on (D7: months with nothing charged are
     * skipped, a partly free month costs only its charged part). A month is
     * bought only when the money left covers all of it.
     *
     * @return array{months: int, cost: string}
     */
    public function monthsBought(string $fromPeriod, string $amount, string $rate, int $cap, int $alreadyCovered, string $fullBill): array
    {
        $months = 0;
        $cost = '0.00';
        $period = $fromPeriod;
        $skipped = 0;

        if (bccomp($rate, '0', self::SCALE) <= 0) {
            return ['months' => 0, 'cost' => '0.00'];
        }

        for ($guard = 0; $guard < 600 && $months < $cap; $guard++, $period = FreeMonthGrant::addMonths($period, 1)) {
            $charge = $this->charge($period, $fullBill);

            if (bccomp($charge, '0', self::SCALE) <= 0) {
                continue;
            }

            if ($skipped < $alreadyCovered) {
                $skipped++;

                continue;
            }

            $price = $this->isPartlyFree($period, $fullBill) && bccomp($charge, $rate, self::SCALE) < 0 ? $charge : $rate;

            if (bccomp(bcadd($cost, $price, self::SCALE), $amount, self::SCALE) > 0) {
                break;
            }

            $cost = bcadd($cost, $price, self::SCALE);
            $months++;
        }

        return ['months' => $months, 'cost' => $cost];
    }

    /**
     * Each service's timeline from $fromPeriod on — free → paid → billed
     * (D15) — exactly as the billing engine will play it: a free month uses
     * no paid month; then the service's own paid months; then any
     * whole-bill months paid ahead cover every service still charged; the
     * first month left is that service's first billed month.
     *
     * @param  array<int, int>  $servicePaidMonths  service id => months paid upfront left
     * @return array{services: array<int, array{free_from: ?string, free_until: ?string, paid_from: ?string, paid_until: ?string, first_billed: ?string}>, first_bill: ?string, paid_until: ?string}
     */
    public function timelines(string $fromPeriod, array $servicePaidMonths = [], int $wholeBillMonths = 0): array
    {
        $services = [];

        foreach (array_keys($this->servicePrices) as $serviceId) {
            $services[$serviceId] = ['free_from' => null, 'free_until' => null, 'paid_from' => null, 'paid_until' => null, 'first_billed' => null];
        }

        $open = array_keys($services);
        $period = $fromPeriod;
        $firstBill = null;
        $wholePaidUntil = null;

        for ($guard = 0; $guard < 600 && $open !== []; $guard++, $period = FreeMonthGrant::addMonths($period, 1)) {
            if ($this->beforeBillingStarts($period)) {
                continue;
            }

            $charged = [];

            foreach ($open as $serviceId) {
                if ($this->isServiceFree($serviceId, $period)) {
                    $services[$serviceId]['free_from'] ??= $period;
                    $services[$serviceId]['free_until'] = $period;

                    continue;
                }

                if (($servicePaidMonths[$serviceId] ?? 0) > 0) {
                    $servicePaidMonths[$serviceId]--;
                    $services[$serviceId]['paid_from'] ??= $period;
                    $services[$serviceId]['paid_until'] = $period;

                    continue;
                }

                $charged[] = $serviceId;
            }

            if ($charged === []) {
                continue;
            }

            if ($wholeBillMonths > 0) {
                $wholeBillMonths--;
                $wholePaidUntil = $period;

                foreach ($charged as $serviceId) {
                    $services[$serviceId]['paid_from'] ??= $period;
                    $services[$serviceId]['paid_until'] = $period;
                }

                continue;
            }

            foreach ($charged as $serviceId) {
                $services[$serviceId]['first_billed'] = $period;
            }

            $firstBill ??= $period;
            $open = array_values(array_diff($open, $charged));
        }

        $paidUntil = $wholePaidUntil;

        foreach ($services as $timeline) {
            if ($timeline['paid_until'] !== null && ($paidUntil === null || $timeline['paid_until'] > $paidUntil)) {
                $paidUntil = $timeline['paid_until'];
            }
        }

        return ['services' => $services, 'first_bill' => $firstBill, 'paid_until' => $paidUntil];
    }

    /**
     * Each free window with its first and last month, per service id (null
     * = every service), from $fromPeriod on.
     *
     * @return list<array{service_id: ?int, start: string, end: string}>
     */
    public function windowsFrom(string $fromPeriod): array
    {
        return array_values(array_filter($this->windows, fn (array $window): bool => $window['end'] >= $fromPeriod));
    }

    /**
     * The last month any service is free, or null when there are no grants.
     */
    public function lastFreePeriod(): ?string
    {
        $last = null;

        foreach ($this->windows as $window) {
            $last = $last === null || $window['end'] > $last ? $window['end'] : $last;
        }

        return $last;
    }
}
