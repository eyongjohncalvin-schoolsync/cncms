<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Customer;
use App\Models\FreeMonthGrant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a customer is charged in a given month once free months and the
 * first billed month are taken into account
 * (docs/plans/installation-and-promotions.md, phase 2) — the one place the
 * billing engine (App\Services\ManuscriptCalculator) and every screen that
 * previews a month (add-customer form, pre-run review, invoices) ask "is
 * this month free, and what is still charged".
 *
 * Stateless by design: free months run BY CALENDAR from each approved
 * grant's start_period (D5), so the answer for any month is a pure function
 * of the customer's approved grants, their per-service prices and
 * `customers.billing_starts_period` — nothing is counted down on the
 * manuscript, so a re-run, a publish/unpublish or a forward recalculation
 * can never double-give or lose a free month.
 *
 *  - Before `billing_starts_period` nothing is charged (D4: the join month
 *    is not billed).
 *  - A service is free in a month any approved grant for it (or an
 *    all-services grant) covers; only the other services are charged (D7).
 *  - The free part is zero-value: freeValue() is reporting only (promo
 *    cost as foregone revenue) and never enters the ledger.
 */
final readonly class FreeMonthPlan
{
    private const int SCALE = 2;

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
     * then per-service prices for the customers that have any) — the
     * manuscript run calls this once per chunk.
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

        $pricesByCustomer = $grantsByCustomer->isEmpty() ? new Collection : DB::table('customer_service')
            ->whereIn('customer_id', $grantsByCustomer->keys()->all())
            ->groupBy('customer_id', 'service_id')
            ->selectRaw('customer_id, service_id, sum(price) as price')
            ->get()
            ->groupBy('customer_id');

        $plans = [];

        foreach ($customers as $customer) {
            $grants = $grantsByCustomer->get($customer->id, new Collection);

            $plans[$customer->id] = new self(
                billingStartsPeriod: $customer->billing_starts_period,
                servicePrices: $pricesByCustomer->get($customer->id, new Collection)
                    ->mapWithKeys(fn (object $row): array => [(int) $row->service_id => bcadd((string) $row->price, '0', self::SCALE)])
                    ->all(),
                windows: $grants
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

    /** True when nothing about this customer differs from plain monthly billing. */
    public function isEmpty(): bool
    {
        return $this->billingStartsPeriod === null && $this->windows === [];
    }

    /**
     * New installations (customers with a first billed month) are billed
     * month by month: a month's charge is shown on that month's bill and
     * only becomes arrears when the next month is calculated. Customers
     * from before phase 2 keep the original ledger unchanged.
     */
    public function billsMonthByMonth(): bool
    {
        return $this->billingStartsPeriod !== null;
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

    /** What is charged this month: the bill less its free services (D7). */
    public function charge(string $period, string $fullBill): string
    {
        if ($this->beforeBillingStarts($period)) {
            return '0.00';
        }

        return bcsub(bcadd($fullBill, '0', self::SCALE), $this->freeValue($period, $fullBill), self::SCALE);
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
     * How many charged months an advance buys from $fromPeriod on (D7: an
     * advance covers exactly what is charged each month — months with
     * nothing charged are skipped, a partly free month costs only its
     * charged part). $alreadyCovered charged months are covered by earlier
     * prepaid months first. A month is bought only when the money left
     * covers all of it.
     *
     * $rate is what a fully charged month costs (the bill locked at payment
     * time); a partly free month costs its charge at today's prices, capped
     * at $rate.
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

        // 600 months is a hard stop against a plan that never charges.
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
