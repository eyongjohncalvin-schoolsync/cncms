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
 * phase 2 item 8, revision D15): each service's timeline — free → paid →
 * billed — and the first month they will be billed, e.g.
 * "TV Service: free Oct–Nov 2026, paid Dec 2026–Feb 2027, billed from Mar 2027".
 *
 * Shown on the customer page, sent to the mobile app and printed on the
 * installation receipt (D11). Derived, never stored: the same
 * App\Support\Billing\FreeMonthPlan the billing engine reads, walked
 * forward from the customer's latest manuscript (payments not yet absorbed
 * by a run are counted as the run will count them).
 */
class CustomerCoverageService
{
    public function __construct(
        private readonly ManuscriptRunLockService $lock,
    ) {}

    /**
     * @return array{services: list<array{service_id: int, service: string, free_from: ?string, free_until: ?string, paid_from: ?string, paid_until: ?string, first_billed: ?string}>, free: list<array{service: string, from: string, until: string}>, waiting: list<array{service: string, months: int, status: string}>, paid_until: ?string, first_bill: ?string, label: ?string}
     */
    public function summarize(Customer $customer): array
    {
        // A field registration awaiting the office: nothing is billed yet,
        // its months are settled at activation (installation phase 3).
        if ($customer->isPendingActivation()) {
            return ['services' => [], 'free' => [], 'waiting' => [], 'paid_until' => null, 'first_bill' => null, 'label' => 'Pending activation — not billed until the office activates'];
        }

        $plan = FreeMonthPlan::forCustomer($customer);
        $bill = bcadd((string) $customer->bill, '0', 2);
        $current = $this->lock->currentPeriod();

        $latest = Manuscript::query()->where('customer_id', $customer->id)->orderByDesc('period')->first();
        $from = $latest !== null ? FreeMonthGrant::addMonths($latest->period, 1) : max($customer->billing_starts_period ?? $current, $current);

        // Paid months still to come: what the latest row carries plus
        // payments no run has absorbed yet.
        $servicePaid = [];

        foreach ((array) ($latest?->service_prepaid ?? []) as $serviceId => $row) {
            $servicePaid[(int) $serviceId] = (int) ($row['months'] ?? 0);
        }

        $wholeBill = (int) ($latest?->prepaid_months_remaining ?? 0);

        $pending = Payment::query()
            ->where('customer_id', $customer->id)
            ->subscription()
            ->where('verification_status', 'verified')
            ->whereIn('frequency', ['months', 'yearly'])
            ->whereNull('expiration_date')
            ->whereNull('processed_period')
            ->orderBy('id')
            ->get();

        foreach ($pending as $payment) {
            if (! empty($payment->service_months)) {
                foreach ((array) $payment->service_months as $row) {
                    $servicePaid[(int) $row['service_id']] = ($servicePaid[(int) $row['service_id']] ?? 0) + (int) $row['months'];
                }

                continue;
            }

            $rate = bcadd((string) ($payment->prepaid_rate ?? $bill), '0', 2);
            $cap = $payment->clear_arrears_first ? PHP_INT_MAX : ($payment->frequency === 'yearly' ? 12 : (int) $payment->months);
            $wholeBill += $plan->monthsBought($from, bcadd((string) $payment->amount, '0', 2), $rate, $cap, $wholeBill, $bill)['months'];
        }

        $walk = $plan->timelines($from, $servicePaid, $wholeBill);
        $serviceNames = Service::query()->pluck('name', 'id');

        $services = [];

        foreach ($walk['services'] as $serviceId => $timeline) {
            $services[] = ['service_id' => (int) $serviceId, 'service' => (string) ($serviceNames[$serviceId] ?? 'Service'), ...$timeline];
        }

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
            'services' => $services,
            'free' => $free,
            'waiting' => $waiting,
            'paid_until' => $walk['paid_until'],
            'first_bill' => $firstBill,
            'label' => $notYetPaying ? $this->label($services, $waiting) : null,
        ];
    }

    /**
     * "TV Service: free Oct–Nov 2026, paid Dec 2026–Feb 2027, billed from
     * Mar 2027 · Internet: billed from Oct 2026".
     *
     * @param  list<array{service: string, free_from: ?string, free_until: ?string, paid_from: ?string, paid_until: ?string, first_billed: ?string}>  $services
     * @param  list<array{service: string, months: int, status: string}>  $waiting
     */
    public function label(array $services, array $waiting = []): ?string
    {
        $parts = [];

        foreach ($services as $timeline) {
            $bits = [];

            if ($timeline['free_from'] !== null) {
                $bits[] = 'free '.self::range($timeline['free_from'], (string) $timeline['free_until']);
            }

            if ($timeline['paid_from'] !== null) {
                $bits[] = 'paid '.self::range($timeline['paid_from'], (string) $timeline['paid_until']);
            }

            if ($timeline['first_billed'] !== null) {
                $bits[] = 'billed from '.self::monthLabel($timeline['first_billed']);
            }

            if ($bits !== []) {
                $parts[] = "{$timeline['service']}: ".implode(', ', $bits);
            }
        }

        foreach ($waiting as $grant) {
            $months = $grant['months'] === 1 ? '1 free month' : "{$grant['months']} free months";
            $parts[] = "{$grant['service']}: {$months} awaiting approval";
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** "Dec 2026" for "2026-12". */
    public static function monthLabel(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period)->format('M Y');
    }

    /** "Oct–Nov 2026", "Dec 2026–Feb 2027", "Oct 2026". */
    public static function range(string $from, string $until): string
    {
        if ($from === $until) {
            return self::monthLabel($from);
        }

        $start = Carbon::createFromFormat('!Y-m', $from);
        $end = Carbon::createFromFormat('!Y-m', $until);

        return $start->year === $end->year
            ? $start->format('M').'–'.$end->format('M Y')
            : $start->format('M Y').'–'.$end->format('M Y');
    }
}
