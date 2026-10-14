<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashLedgerEntry;
use App\Models\Expenditure;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Posts money that changes hands elsewhere in the app to the cash ledger,
 * automatically:
 *
 * - a payment collected in cash goes into the collector's till; a MoMo /
 *   bank payment into that company account. Rejecting or deleting the
 *   payment reverses it.
 * - an expense paid in cash comes out of the recorder's till (it may go
 *   negative: the company then owes them); MoMo / bank expenses come out
 *   of those accounts. Rejecting or voiding the expense reverses it.
 *
 * Payments and expenses recorded before methods existed (method NULL)
 * never touch the ledger.
 */
class CashPostingService
{
    public function __construct(
        private readonly CashLedgerService $ledger,
    ) {}

    public function paymentRecorded(Payment $payment, ?User $collector): void
    {
        if ($payment->method === null || $collector === null || bccomp((string) $payment->amount, '0', 2) <= 0) {
            return;
        }

        $this->ledger->post(
            $this->ledger->accountForMethod($payment->method, $collector),
            CashLedgerEntry::IN,
            (string) $payment->amount,
            CashLedgerEntry::TYPE_COLLECTION,
            ['type' => 'payment', 'id' => $payment->id],
            Carbon::parse($payment->collected_at ?? $payment->created_at ?? Carbon::now()),
            $collector,
            $payment->customer?->name,
        );
    }

    public function paymentReversed(Payment $payment, ?User $by, string $why): void
    {
        $this->ledger->reverseSource('payment', $payment->id, $by, $why);
    }

    public function expenseRecorded(Expenditure $expenditure, User $recorder): void
    {
        if ($expenditure->payment_method === null) {
            return;
        }

        $this->ledger->post(
            $this->ledger->accountForMethod($expenditure->payment_method, $recorder),
            CashLedgerEntry::OUT,
            (string) $expenditure->amount,
            CashLedgerEntry::TYPE_EXPENSE,
            ['type' => 'expenditure', 'id' => $expenditure->id],
            Carbon::parse($expenditure->device_recorded_at ?? Carbon::now()),
            $recorder,
            trim(($expenditure->category?->name ?? 'Expense').' '.($expenditure->description ?? '')),
            allowNegative: true,
        );
    }

    public function expenseReversed(Expenditure $expenditure, ?User $by, string $why): void
    {
        $this->ledger->reverseSource('expenditure', $expenditure->id, $by, $why);
    }
}
