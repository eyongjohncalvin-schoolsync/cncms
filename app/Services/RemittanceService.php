<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The daily income form (docs/plans/cash-controls.md, wave 4).
 *
 *  1. preview()  — the system fills in what the collector should hand over:
 *     every payment they recorded that hasn't gone through a handover yet
 *     (rejected payments excluded, nothing from before cash controls went
 *     live), split into cash / MTN MoMo / Orange Money / bank.
 *  2. submit()   — the collector declares the cash they hold (ideally note
 *     by note) and hands it over; each payment is locked into exactly one
 *     remittance.
 *  3. receive()  — a DIFFERENT person (the cashier) counts the cash, ticks
 *     each mobile-money line seen in the company wallet, and receives it
 *     into their till. The cash variance is kept; a shortage stays open on
 *     the collector until repaid or written off.
 */
class RemittanceService
{
    public function __construct(
        private readonly CashBookService $cashBook,
        private readonly DocumentNumberService $documentNumbers,
        private readonly NotificationService $notifications,
        private readonly CashNotificationService $cashNotifier,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * @return Builder<Payment>
     */
    public function outstandingQuery(User $collector): Builder
    {
        $start = Company::cached()?->cash_controls_start_date;

        return Payment::query()
            ->where('recorded_by', $collector->id)
            // Money taken with a field registration the office rejected
            // (installation phase 3) is still handed over: receipts were
            // issued, and its refund is paid out through a bon.
            ->where(fn ($query) => $query->where('verification_status', '!=', 'rejected')->orWhereNotNull('field_installation_id'))
            ->whereDoesntHave('remittanceLine')
            ->when($start !== null, fn ($query) => $query->whereDate('created_at', '>=', $start));
    }

    /**
     * Approved bons this collector already paid out of their collections
     * ("already spent from my collections",
     * docs/plans/expenditures-and-spending-controls.md item 2) and not yet
     * handed over — the cashier pays them as part of receiving.
     *
     * @return Builder<Voucher>
     */
    public function outstandingBonsQuery(User $collector): Builder
    {
        return Voucher::query()
            ->where('requested_by', $collector->id)
            ->where('from_collections', true)
            ->where('status', 'approved')
            ->whereNull('remittance_id');
    }

    /**
     * @return array{payments: Collection<int, Payment>, totals: array{cash: string, mtn_momo: string, orange_money: string, bank: string}, purpose_totals: array{subscription: string, installation: string, technician_fee: string}, oldest_at: ?string, bons: Collection<int, Voucher>, bons_total: string, cash_to_hand_over: string}
     */
    public function preview(User $collector): array
    {
        $payments = $this->outstandingQuery($collector)->with(['customer', 'receipt'])->orderBy('created_at')->get();
        $bons = $this->outstandingBonsQuery($collector)->orderBy('id')->get();
        $totals = $this->totalsByMethod($payments);
        $bonsTotal = $this->sumAmounts($bons);

        return [
            'bons' => $bons,
            'bons_total' => $bonsTotal,
            'cash_to_hand_over' => bcsub($totals['cash'], $bonsTotal, 2),
            'payments' => $payments,
            'totals' => $this->totalsByMethod($payments),
            // Technician fees ride along with the day's cash but are the
            // technician's money (D13) — shown as their own subtotal so the
            // cashier counts them and knows what to pay out.
            'purpose_totals' => $this->totalsByPurpose($payments),
            'oldest_at' => $payments->first()?->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, int>|null  $denominations
     */
    public function submit(User $collector, string $declaredCash, ?array $denominations, ?string $note): Remittance
    {
        if ($denominations !== null && bccomp(CashBookService::denominationTotal($denominations), $declaredCash, 2) !== 0) {
            throw ValidationException::withMessages(['declared_cash' => 'The notes and coins you counted don\'t add up to the cash you declared.']);
        }

        return DB::transaction(function () use ($collector, $declaredCash, $denominations, $note): Remittance {
            $payments = $this->outstandingQuery($collector)->lockForUpdate()->orderBy('created_at')->get();

            if ($payments->isEmpty()) {
                throw ValidationException::withMessages(['declared_cash' => 'You have no collections waiting to be handed over.']);
            }

            $totals = $this->totalsByMethod($payments);
            $bons = $this->outstandingBonsQuery($collector)->lockForUpdate()->get();

            $remittance = Remittance::query()->create([
                'bons_total' => $this->sumAmounts($bons),
                'number' => $this->documentNumbers->next(DocumentNumberService::REMITTANCE),
                'collector_user_id' => $collector->id,
                'business_date' => $this->cashBook->today(),
                'status' => 'submitted',
                'expected_cash' => $totals['cash'],
                'expected_mtn' => $totals['mtn_momo'],
                'expected_orange' => $totals['orange_money'],
                'expected_bank' => $totals['bank'],
                'declared_cash' => $declaredCash,
                'declared_denominations' => $denominations,
                'collector_note' => $note,
                'submitted_at' => now(),
            ]);

            $now = now();

            RemittanceLine::query()->insert($payments->map(fn (Payment $payment): array => [
                'remittance_id' => $remittance->id,
                'payment_id' => $payment->id,
                'method' => $payment->method ?? 'cash',
                'amount' => $payment->amount,
                'reference' => $payment->method_reference,
                'wallet_confirmed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            if ($bons->isNotEmpty()) {
                Voucher::query()->whereKey($bons->modelKeys())->update(['remittance_id' => $remittance->id]);
            }

            return $remittance;
        });
    }

    /**
     * @param  array<string, int>|null  $denominations
     * @param  list<int>  $confirmedLineIds  mobile-money lines seen in the wallet
     */
    public function receive(Remittance $remittance, User $receiver, string $receivedCash, ?array $denominations, array $confirmedLineIds, ?string $note): Remittance
    {
        if ($denominations !== null && bccomp(CashBookService::denominationTotal($denominations), $receivedCash, 2) !== 0) {
            throw ValidationException::withMessages(['received_cash' => 'The notes and coins you counted don\'t add up to the cash received.']);
        }

        $remittance = DB::transaction(function () use ($remittance, $receiver, $receivedCash, $denominations, $confirmedLineIds, $note): Remittance {
            $locked = Remittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isSubmitted()) {
                throw ValidationException::withMessages(['received_cash' => "Remittance {$locked->number} has already been received."]);
            }

            $till = $this->cashBook->tillFor($receiver);

            if (bccomp($receivedCash, '0', 2) > 0) {
                $this->cashBook->record($till, 'in', $receivedCash, CashMovement::KIND_REMITTANCE_CASH, $receiver, [
                    'document_number' => $locked->number,
                    'source' => $locked,
                    'note' => 'Handed over by '.($locked->collector?->name ?? 'collector'),
                ]);
            }

            foreach ($locked->lines()->get() as $line) {
                if (! array_key_exists($line->method, CashAccount::WALLET_PROVIDERS) || ! in_array($line->id, $confirmedLineIds, true)) {
                    continue;
                }

                $line->update(['wallet_confirmed' => true]);

                $this->cashBook->record($this->cashBook->walletFor($line->method), 'in', (string) $line->amount, CashMovement::KIND_REMITTANCE_WALLET, $receiver, [
                    'document_number' => $locked->number,
                    'source' => $locked,
                    'reference' => $line->reference,
                ]);
            }

            // Bons the collector already paid out of this cash: the cashier
            // pays each one now (cash in, straight back out on the bon), so
            // the drawer is expected to hold the collections minus them.
            $bonsPaid = '0.00';

            foreach ($locked->bons()->lockForUpdate()->get() as $bon) {
                if ($this->vouchers->payAtHandover($bon, $receiver, $till, $locked->collector?->name ?? 'Collector')) {
                    $bonsPaid = bcadd($bonsPaid, (string) $bon->amount, 2);
                }
            }

            $variance = bcsub(bcadd($receivedCash, $bonsPaid, 2), (string) $locked->expected_cash, 2);

            $locked->update([
                'status' => 'received',
                'received_by' => $receiver->id,
                'received_at' => now(),
                'till_account_id' => $till->id,
                'received_cash' => $receivedCash,
                'received_denominations' => $denominations,
                'cash_variance' => $variance,
                'receiver_note' => $note,
                'bons_total' => $bonsPaid,
                'shortage_status' => bccomp($variance, '0', 2) < 0 ? 'open' : null,
            ]);

            return $locked;
        });

        $this->notifyReceived($remittance);

        return $remittance;
    }

    /**
     * Clears an open shortage: the collector paid the missing cash into the
     * receiver's till (`repaid`), or a manager accepts the loss
     * (`written_off`). Either way the remittance keeps its variance.
     */
    public function settleShortage(Remittance $remittance, string $outcome, User $actor, ?string $note): Remittance
    {
        if ($remittance->shortage_status !== 'open') {
            throw ValidationException::withMessages(['outcome' => 'This remittance has no open shortage.']);
        }

        return DB::transaction(function () use ($remittance, $outcome, $actor, $note): Remittance {
            if ($outcome === 'repaid') {
                $this->cashBook->record($this->cashBook->tillFor($actor), 'in', ltrim((string) $remittance->cash_variance, '-'), CashMovement::KIND_SHORTAGE_REPAYMENT, $actor, [
                    'document_number' => $remittance->number,
                    'source' => $remittance,
                    'note' => $note,
                ]);
            }

            $remittance->update([
                'shortage_status' => $outcome,
                'shortage_settled_by' => $actor->id,
                'shortage_settled_at' => now(),
                'shortage_note' => $note,
            ]);

            return $remittance;
        });
    }

    /**
     * What the handed-over money is for: subscription, installation, and the
     * technician's pass-through fees (docs/plans/installation-and-promotions.md).
     *
     * @param  Collection<int, Payment>  $payments
     * @return array{subscription: string, installation: string, technician_fee: string}
     */
    public function totalsByPurpose(Collection $payments): array
    {
        $totals = array_fill_keys(Payment::PURPOSES, '0.00');

        foreach ($payments as $payment) {
            $purpose = $payment->purpose ?? Payment::PURPOSE_SUBSCRIPTION;
            $totals[$purpose] = bcadd($totals[$purpose] ?? '0.00', (string) $payment->amount, 2);
        }

        return $totals;
    }

    /**
     * @param  Collection<int, Voucher>  $bons
     */
    private function sumAmounts(Collection $bons): string
    {
        return $bons->reduce(fn (string $carry, Voucher $bon): string => bcadd($carry, (string) $bon->amount, 2), '0.00');
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return array{cash: string, mtn_momo: string, orange_money: string, bank: string}
     */
    private function totalsByMethod(Collection $payments): array
    {
        $totals = ['cash' => '0.00', 'mtn_momo' => '0.00', 'orange_money' => '0.00', 'bank' => '0.00'];

        foreach ($payments as $payment) {
            $method = $payment->method ?? 'cash';
            $totals[$method] = bcadd($totals[$method] ?? '0.00', (string) $payment->amount, 2);
        }

        return $totals;
    }

    private function notifyReceived(Remittance $remittance): void
    {
        $remittance->loadMissing(['collector', 'receivedBy']);
        $money = fn ($value): string => number_format((float) $value, 0, '.', ' ').' FCFA';
        $short = bccomp((string) $remittance->cash_variance, '0', 2) < 0;

        if ($remittance->collector) {
            $this->notifications->broadcastToUser(
                $remittance->collector,
                'remittance.received',
                $short ? 'warning' : 'info',
                $short ? 'Handover short' : 'Handover received',
                "{$remittance->number}: {$remittance->receivedBy?->name} received {$money($remittance->received_cash)} cash"
                    .($short ? ", {$money(ltrim((string) $remittance->cash_variance, '-'))} short of what you collected." : '.'),
                "/cash/remittances/{$remittance->uuid}",
                'remittance',
                $remittance->uuid,
            );
        }

        if ($short) {
            $this->cashNotifier->toHoldersOf(
                'cash.view',
                'remittance.shortage',
                'warning',
                'Cash handover short',
                "{$remittance->collector?->name} handed over {$money($remittance->received_cash)} against {$money($remittance->expected_cash)} collected ({$remittance->number}).",
                "/cash/remittances/{$remittance->uuid}",
                'remittance',
                $remittance->uuid,
            );
        }
    }
}
