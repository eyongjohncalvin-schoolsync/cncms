<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\FreeMonthGrant;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Service;
use App\Support\Billing\FreeMonthPlan;
use Illuminate\Support\Carbon;

/**
 * The customer's coverage line (docs/plans/installation-and-promotions.md,
 * phase 2, item 8): which services are free until when, until when they
 * have paid in advance, and the first month they will be billed — e.g.
 * "TV free until Dec 2026 · paid until Mar 2027 · first bill Apr 2027".
 *
 * Shown on the customer page, sent to the mobile app and printed on the
 * installation receipt (D11). Derived, never stored: the same
 * App\Support\Billing\FreeMonthPlan the billing engine reads, walked
 * forward from the customer's latest manuscript (advances not yet absorbed
 * by a run are counted as the run will count them).
 */
class CustomerCoverageService
{
    public function __construct(
        private readonly ManuscriptRunLockService $lock,
    ) {}

    /**
     * @return array{free: list<array{service: string, from: string, until: string}>, waiting: list<array{service: string, months: int, status: string}>, paid_from: ?string, paid_until: ?string, first_bill: ?string, label: ?string}
     */
    public function summarize(Customer $customer): array
    {
        $plan = FreeMonthPlan::forCustomer($customer);
        $bill = bcadd((string) $customer->bill, '0', 2);
        $current = $this->lock->currentPeriod();

        $latest = Manuscript::query()->where('customer_id', $customer->id)->orderByDesc('period')->first();
        $from = $latest !== null ? FreeMonthGrant::addMonths($latest->period, 1) : FreeMonthGrant::addMonths($current, 1);

        if ($customer->billing_starts_period !== null && $from < $customer->billing_starts_period) {
            $from = $customer->billing_starts_period;
        }

        $prepaid = (int) ($latest?->prepaid_months_remaining ?? 0) + $this->unabsorbedAdvanceMonths($customer, $plan, $from, (int) ($latest?->prepaid_months_remaining ?? 0), $bill);
        $walk = $plan->coverage($from, $bill, $prepaid);

        $serviceNames = Service::query()->pluck('name', 'id');

        $free = array_map(fn (array $window): array => [
            'service' => $window['service_id'] === null ? 'All services' : (string) ($serviceNames[$window['service_id']] ?? 'Service'),
            'from' => $window['start'],
            'until' => $window['end'],
        ], $plan->windowsFrom($current));

        $waiting = FreeMonthGrant::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT, FreeMonthGrant::STATUS_PENDING_APPROVAL])
            ->get()
            ->map(fn (FreeMonthGrant $grant): array => [
                'service' => $grant->service_id === null ? 'All services' : (string) ($serviceNames[$grant->service_id] ?? 'Service'),
                'months' => (int) $grant->months,
                'status' => $grant->status,
            ])
            ->values()
            ->all();

        // A first bill is only news while the customer is not yet paying:
        // before billing starts, during free months or paid months.
        $notYetPaying = $latest === null || $free !== [] || $walk['paid_until'] !== null;
        $firstBill = $notYetPaying ? $walk['first_bill'] : null;

        return [
            'free' => $free,
            'waiting' => $waiting,
            'paid_from' => $walk['paid_from'],
            'paid_until' => $walk['paid_until'],
            'first_bill' => $firstBill,
            'label' => $this->label($free, $waiting, $walk['paid_until'], $firstBill),
        ];
    }

    /**
     * "TV free until Dec 2026 · paid until Mar 2027 · first bill Apr 2027".
     *
     * @param  list<array{service: string, from: string, until: string}>  $free
     * @param  list<array{service: string, months: int, status: string}>  $waiting
     */
    public function label(array $free, array $waiting, ?string $paidUntil, ?string $firstBill): ?string
    {
        $parts = [];

        foreach ($free as $window) {
            $parts[] = "{$window['service']} free until ".self::monthLabel($window['until']);
        }

        foreach ($waiting as $grant) {
            $months = $grant['months'] === 1 ? '1 free month' : "{$grant['months']} free months";
            $parts[] = $grant['status'] === FreeMonthGrant::STATUS_PENDING_APPROVAL
                ? "{$grant['service']}: {$months} awaiting approval"
                : "{$grant['service']}: {$months} once installation is paid";
        }

        if ($paidUntil !== null) {
            $parts[] = 'paid until '.self::monthLabel($paidUntil);
        }

        if ($firstBill !== null) {
            $parts[] = 'first bill '.self::monthLabel($firstBill);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** "Dec 2026" for "2026-12". */
    public static function monthLabel(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period)->format('M Y');
    }

    /**
     * Advance (months/yearly) payments verified but not yet absorbed by a
     * run — counted exactly as the run will count them.
     */
    private function unabsorbedAdvanceMonths(Customer $customer, FreeMonthPlan $plan, string $from, int $alreadyCovered, string $bill): int
    {
        $months = 0;

        $advances = Payment::query()
            ->where('customer_id', $customer->id)
            ->subscription()
            ->where('verification_status', 'verified')
            ->whereIn('frequency', ['months', 'yearly'])
            ->whereNull('expiration_date')
            ->whereNull('processed_period')
            ->orderBy('id')
            ->get();

        foreach ($advances as $payment) {
            $rate = bcadd((string) ($payment->prepaid_rate ?? $bill), '0', 2);
            $cap = $payment->clear_arrears_first ? PHP_INT_MAX : ($payment->frequency === 'yearly' ? 12 : (int) $payment->months);
            $months += $plan->monthsBought($from, bcadd((string) $payment->amount, '0', 2), $rate, $cap, $alreadyCovered + $months, $bill)['months'];
        }

        return $months;
    }
}
