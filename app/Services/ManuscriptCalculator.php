<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The monthly manuscript billing engine.
 *
 * Computes, for a single customer and a single 'YYYY-MM' period, the arrears,
 * credit, and total_bill that `manuscript:calculate` should write to the
 * `manuscripts` table — without touching the database itself. The caller
 * (currently the manuscript:calculate console command) is responsible for
 * persisting the returned ManuscriptCalculationResult and for stamping
 * `processed_at` on the payments it says were consumed.
 *
 * Ledger model
 * ------------
 * Only `verification_status = 'verified'` payments with `processed_at IS NULL`
 * are treated as income for a period — pending and rejected payments never
 * affect billing (business-rules.md #2, #9).
 *
 * Each period carries forward a single net figure from the previous period:
 *
 *     previousNet = previousManuscript.total_arrears - previousManuscript.credit
 *
 * (or, for a customer's very first manuscript ever, previousNet = customers.others
 * — the imported seed balance, applied exactly once per business-rules.md #8).
 *
 *     net = previousNet + bill - income
 *
 * A positive net is arrears (still owed); a negative net becomes credit. This
 * is what makes credit "consumed before arrears": leftover credit from an
 * overpayment reduces `previousNet` below zero, which directly offsets next
 * period's bill before any new arrears can accrue. total_bill is then
 * `bill + total_arrears - credit`, clamped to a minimum of 0, matching the
 * core formula in database-schema.md.
 *
 * Special cases (business-rules.md #6, #7, #8):
 * - `disconnected` and `passive` customers accrue nothing: total_arrears and
 *   credit are carried forward unchanged, total_bill is forced to 0, and no
 *   payments are consumed (so a reconnection payment recorded while still
 *   disconnected is picked up cleanly by the next normal run instead of
 *   being silently absorbed while frozen).
 * - A customer with a `months`/`yearly` frequency payment sets/extends
 *   `payment_expiration`. While that date is still in the future, billing is
 *   frozen the same way (total_bill = 0, arrears/credit untouched) — the
 *   prepayment's value is "spent" via the expiration mechanism, not the
 *   arrears/credit ledger. Only the payment(s) that established the
 *   expiration are marked processed; normal monthly billing resumes
 *   automatically once `payment_expiration` is no longer in the future.
 */
final class ManuscriptCalculator
{
    private const SCALE = 2;

    public function calculate(Customer $customer, string $period, ?Carbon $asOf = null): ManuscriptCalculationResult
    {
        $asOf ??= Carbon::now();

        $billDue = $this->normalize((string) $customer->bill);

        $previousManuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->first();

        $isFirstRun = $previousManuscript === null;

        $previousArrears = $isFirstRun
            ? $this->normalize((string) $customer->others)
            : $this->normalize((string) $previousManuscript->total_arrears);

        $previousCredit = $isFirstRun
            ? '0.00'
            : $this->normalize((string) $previousManuscript->credit);

        $previousExpiration = $isFirstRun ? null : $previousManuscript->payment_expiration;

        $unprocessedVerifiedPayments = Payment::query()
            ->where('customer_id', $customer->id)
            ->whereNull('processed_at')
            ->where('verification_status', 'verified')
            ->get();

        if (in_array($customer->status, ['disconnected', 'passive'], true)) {
            return new ManuscriptCalculationResult(
                bill: $billDue,
                totalArrears: $previousArrears,
                credit: $previousCredit,
                totalBill: '0.00',
                paymentExpiration: $previousExpiration ? Carbon::parse($previousExpiration) : null,
                income: '0.00',
                isFirstRun: $isFirstRun,
                isFrozen: true,
                frozenReason: $customer->status,
                processedPayments: new Collection,
            );
        }

        $expiringPayments = $unprocessedVerifiedPayments
            ->filter(fn (Payment $payment): bool => $payment->expiration_date !== null);

        $candidateExpiration = $expiringPayments
            ->map(fn (Payment $payment): Carbon => Carbon::parse($payment->expiration_date))
            ->when($previousExpiration, fn (Collection $dates) => $dates->push(Carbon::parse($previousExpiration)))
            ->max();

        if ($candidateExpiration !== null && $candidateExpiration->greaterThan($asOf)) {
            return new ManuscriptCalculationResult(
                bill: $billDue,
                totalArrears: $previousArrears,
                credit: $previousCredit,
                totalBill: '0.00',
                paymentExpiration: $candidateExpiration,
                income: '0.00',
                isFirstRun: $isFirstRun,
                isFrozen: true,
                frozenReason: 'prepaid',
                processedPayments: $expiringPayments->values(),
            );
        }

        $income = $unprocessedVerifiedPayments->reduce(
            fn (string $carry, Payment $payment): string => $this->add($carry, $this->normalize((string) $payment->amount)),
            '0.00'
        );

        $net = $this->add($this->sub($previousArrears, $previousCredit), $this->sub($billDue, $income));

        if ($this->compare($net, '0.00') > 0) {
            $totalArrears = $net;
            $credit = '0.00';
        } else {
            $totalArrears = '0.00';
            $credit = $this->negate($net);
        }

        $totalBill = $this->add($billDue, $this->sub($totalArrears, $credit));

        if ($this->compare($totalBill, '0.00') < 0) {
            $totalBill = '0.00';
        }

        return new ManuscriptCalculationResult(
            bill: $billDue,
            totalArrears: $totalArrears,
            credit: $credit,
            totalBill: $totalBill,
            paymentExpiration: null,
            income: $income,
            isFirstRun: $isFirstRun,
            isFrozen: false,
            frozenReason: null,
            processedPayments: $unprocessedVerifiedPayments->values(),
        );
    }

    private function normalize(string $value): string
    {
        return bcadd($value, '0.00', self::SCALE);
    }

    private function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    private function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    private function negate(string $a): string
    {
        return bcsub('0.00', $a, self::SCALE);
    }

    private function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }
}
