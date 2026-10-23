<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Monthly invoices and the statement of account. Each month's bill (the
 * customer's manuscript for that period) IS the invoice: it already carries
 * a stable number (Manuscript::billNumber()) and the amount asked for
 * (total_bill). What it lacked was a paid status:
 *
 *   paid toward bill P = verified payments the NEXT month's calculation
 *   consumed (processed_period = P + 1) — or, for the latest bill, verified
 *   payments no calculation has consumed yet.
 *
 * That mirrors how the billing engine itself applies money, so the status
 * never disagrees with the balance on the next bill.
 */
class InvoiceService
{
    public const string STATUS_PAID = 'paid';

    public const string STATUS_PARTLY_PAID = 'partly_paid';

    public const string STATUS_UNPAID = 'unpaid';

    public const string STATUS_NOTHING_DUE = 'nothing_due';

    /**
     * The customer's most recent bills, newest first.
     *
     * @return list<array{period: string, number: string, amount_due: string, paid: string, status: string, balance: string, prepaid_months_remaining: int}>
     */
    public function billsFor(Customer $customer, int $limit = 12): array
    {
        $manuscripts = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('period')
            ->limit($limit)
            ->get();

        return array_reverse($this->rows($customer, $manuscripts->sortBy('period')->values()));
    }

    /**
     * Everything the statement PDF shows for $from..$to (YYYY-MM, inclusive):
     * opening balance, one line per bill, every verified payment received in
     * the range (with its receipt number), closing balance. Balances are
     * "owes" positive, "in credit" negative.
     *
     * @return array{customer: Customer, from: string, to: string, opening_balance: string, closing_balance: string, bills: list<array<string, mixed>>, payments: list<array{date: ?string, amount: string, receipt_number: ?string, method: string}>, total_billed: string, total_paid: string}
     */
    public function statement(Customer $customer, string $from, string $to): array
    {
        $manuscripts = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->whereBetween('period', [$from, $to])
            ->orderBy('period')
            ->get();

        $before = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', '<', $from)
            ->orderByDesc('period')
            ->first();

        $opening = $before !== null
            ? bcsub((string) $before->total_arrears, (string) $before->credit, 2)
            : bcadd((string) $customer->others, '0', 2);

        $start = Carbon::createFromFormat('!Y-m', $from)->startOfMonth();
        $end = Carbon::createFromFormat('!Y-m', $to)->endOfMonth();

        // By the day the money was collected (an agent's offline collection
        // time when there is one), which is what a customer remembers.
        $payments = Payment::query()
            ->with('receipt')
            ->where('customer_id', $customer->id)
            ->subscription()
            ->where('verification_status', 'verified')
            ->whereRaw('coalesce(collected_at, created_at) between ? and ?', [$start, $end])
            ->orderByRaw('coalesce(collected_at, created_at)')
            ->get();

        $bills = $this->rows($customer, $manuscripts);
        $last = $manuscripts->last();

        return [
            'customer' => $customer,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'closing_balance' => $last !== null ? bcsub((string) $last->total_arrears, (string) $last->credit, 2) : $opening,
            'bills' => $bills,
            'payments' => $payments->map(fn (Payment $payment): array => [
                'date' => ($payment->collected_at ?? $payment->created_at)?->toDateString(),
                'amount' => bcadd((string) $payment->amount, '0', 2),
                'receipt_number' => $payment->receipt?->isVoid() === false ? $payment->receipt->receipt_number : null,
                'method' => $payment->frequency,
            ])->all(),
            'total_billed' => array_reduce($bills, fn (string $sum, array $bill): string => bcadd($sum, $bill['monthly_charge'], 2), '0.00'),
            'total_paid' => $payments->reduce(fn (string $sum, Payment $payment): string => bcadd($sum, (string) $payment->amount, 2), '0.00'),
        ];
    }

    /**
     * @param  Collection<int, Manuscript>  $manuscripts  oldest first
     * @return list<array{period: string, number: string, monthly_charge: string, amount_due: string, paid: string, status: string, balance: string, prepaid_months_remaining: int}>
     */
    private function rows(Customer $customer, Collection $manuscripts): array
    {
        if ($manuscripts->isEmpty()) {
            return [];
        }

        $latestPeriod = (string) Manuscript::query()->where('customer_id', $customer->id)->max('period');

        // Verified payments grouped by the period that consumed them (''
        // = not consumed yet) — one query for the whole list.
        $paidByProcessedPeriod = Payment::query()
            ->where('customer_id', $customer->id)
            ->subscription()
            ->where('verification_status', 'verified')
            ->selectRaw("coalesce(processed_period, '') as processed_period, sum(amount) as total")
            ->groupByRaw("coalesce(processed_period, '')")
            ->pluck('total', 'processed_period');

        return $manuscripts->map(function (Manuscript $manuscript) use ($customer, $latestPeriod, $paidByProcessedPeriod): array {
            $next = Carbon::createFromFormat('!Y-m', $manuscript->period)->addMonthNoOverflow()->format('Y-m');
            $paid = (string) ($paidByProcessedPeriod[$next] ?? '0');

            if ($manuscript->period === $latestPeriod) {
                $paid = bcadd($paid, (string) ($paidByProcessedPeriod[''] ?? '0'), 2);
            }

            $due = bcadd((string) $manuscript->total_bill, '0', 2);
            $paid = bcadd($paid, '0', 2);

            return [
                'period' => $manuscript->period,
                'number' => Manuscript::billNumberFor($customer->id, $manuscript->period),
                'monthly_charge' => bcadd((string) $manuscript->bill, '0', 2),
                'amount_due' => $due,
                'paid' => $paid,
                'status' => self::status($due, $paid),
                'balance' => bcsub((string) $manuscript->total_arrears, (string) $manuscript->credit, 2),
                'prepaid_months_remaining' => (int) $manuscript->prepaid_months_remaining,
                // Free months (installation phase 2) are never billed: the
                // month's charge above already leaves the free services out;
                // free_value is what they would have cost (zero-value).
                'free_value' => bcadd((string) ($manuscript->free_value ?? '0'), '0', 2),
                'is_free' => $manuscript->isFreeMonth(),
            ];
        })->values()->all();
    }

    public static function status(string $due, string $paid): string
    {
        return match (true) {
            bccomp($due, '0', 2) <= 0 => self::STATUS_NOTHING_DUE,
            bccomp($paid, $due, 2) >= 0 => self::STATUS_PAID,
            bccomp($paid, '0', 2) > 0 => self::STATUS_PARTLY_PAID,
            default => self::STATUS_UNPAID,
        };
    }
}
