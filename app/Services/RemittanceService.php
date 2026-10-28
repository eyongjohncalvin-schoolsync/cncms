<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Models\User;
use App\Models\Voucher;
use App\Support\BranchAttribution;
use App\Support\TenantContext;
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
        // Rejected payments too, unless the office said no money was taken
        // (Payment::scopeAwaitingHandover()) — e.g. a field registration the
        // office rejected: its refund is paid out through a bon.
        return Payment::query()->where('recorded_by', $collector->id)->awaitingHandover();
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
                'branch_id' => BranchAttribution::forUser($collector),
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
     * @param  list<int>  $confirmedLineIds  MoMo / Orange / bank lines seen on the account's statement
     */
    public function receive(Remittance $remittance, User $receiver, string $receivedCash, ?array $denominations, array $confirmedLineIds, ?string $note, ?string $bankAccountUuid = null): Remittance
    {
        if ($denominations !== null && bccomp(CashBookService::denominationTotal($denominations), $receivedCash, 2) !== 0) {
            throw ValidationException::withMessages(['received_cash' => 'The notes and coins you counted don\'t add up to the cash received.']);
        }

        $this->assertNotOwnHandover($remittance, $receiver, 'received_cash');

        $remittance = DB::transaction(function () use ($remittance, $receiver, $receivedCash, $denominations, $confirmedLineIds, $note, $bankAccountUuid): Remittance {
            $locked = Remittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isSubmitted()) {
                throw ValidationException::withMessages(['received_cash' => "Remittance {$locked->number} has already been received."]);
            }

            $till = $this->cashBook->tillFor($receiver);

            // Branches v2 §4.7: a collector's cash goes into their own branch's till.
            if ($till->branch_id !== $locked->branch_id) {
                throw ValidationException::withMessages(['received_cash' => "{$locked->number} belongs to another branch — it is received there."]);
            }

            if (bccomp($receivedCash, '0', 2) > 0) {
                $this->cashBook->record($till, 'in', $receivedCash, CashMovement::KIND_REMITTANCE_CASH, $receiver, [
                    'document_number' => $locked->number,
                    'source' => $locked,
                    'note' => 'Handed over by '.($locked->collector?->name ?? 'collector'),
                ]);
            }

            foreach ($locked->lines()->get() as $line) {
                if ($line->isNonCash() && in_array($line->id, $confirmedLineIds, true)) {
                    $this->bookFound($locked, $line, $receiver, $bankAccountUuid, null);
                }
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
            $short = bccomp($variance, '0', 2) < 0;
            $covered = $short ? $this->coverFromCredit($locked, ltrim($variance, '-')) : '0.00';

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
                // Extra cash is the collector's credit for later shortages.
                'credit_remaining' => bccomp($variance, '0', 2) > 0 ? $variance : null,
                'shortage_covered' => $covered,
                'shortage_status' => ! $short ? null : (bccomp($covered, ltrim($variance, '-'), 2) === 0 ? 'repaid' : 'open'),
                'shortage_settled_at' => $short && bccomp($covered, ltrim($variance, '-'), 2) === 0 ? now() : null,
                'shortage_note' => bccomp($covered, '0', 2) > 0 ? number_format((float) $covered, 0, '.', ' ').' FCFA covered by extra cash handed in earlier.' : null,
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
        $this->assertNotOwnHandover($remittance, $actor);

        return DB::transaction(function () use ($remittance, $outcome, $actor, $note): Remittance {
            $remittance = Remittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();

            if ($remittance->shortage_status !== 'open') {
                throw ValidationException::withMessages(['outcome' => 'This remittance has no open shortage.']);
            }

            if ($outcome === 'repaid') {
                $this->cashBook->record($this->branchTill($remittance, $actor), 'in', $remittance->shortageOwed(), CashMovement::KIND_SHORTAGE_REPAYMENT, $actor, [
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
     * A MoMo / Orange / bank line the cashier could not see at the handover
     * is still owed by the collector (owner, 2026-10-28). It is settled one
     * of three ways, never by the collector themselves:
     *  - `found`       — it shows on the statement after all: booked into
     *                    the branch's wallet or bank account (`cash.receive`);
     *  - `repaid`      — the collector pays it in cash into the settler's
     *                    till (`cash.receive`);
     *  - `written_off` — a manager accepts the loss, with a reason
     *                    (`vouchers.approve`).
     */
    public function settleLine(RemittanceLine $line, string $outcome, User $actor, ?string $note, ?string $bankAccountUuid = null): RemittanceLine
    {
        $remittance = $line->remittance;
        $this->assertNotOwnHandover($remittance, $actor);

        return DB::transaction(function () use ($line, $remittance, $outcome, $actor, $note, $bankAccountUuid): RemittanceLine {
            $line = RemittanceLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            if (! $remittance->isReceived() || ! $line->isOwed()) {
                throw ValidationException::withMessages(['outcome' => 'This payment is not waiting to be found.']);
            }

            match ($outcome) {
                RemittanceLine::OUTCOME_FOUND => $this->bookFound($remittance, $line, $actor, $bankAccountUuid, $note),
                RemittanceLine::OUTCOME_REPAID => $this->settle($line, RemittanceLine::OUTCOME_REPAID, $actor, $note, $this->cashBook->record($this->branchTill($remittance, $actor), 'in', (string) $line->amount, CashMovement::KIND_SHORTAGE_REPAYMENT, $actor, [
                    'document_number' => $remittance->number,
                    'source' => $remittance,
                    'reference' => $line->reference,
                    'note' => 'Not found as '.(Payment::METHOD_LABELS[$line->method] ?? $line->method).' — paid in cash'.($note ? ": {$note}" : ''),
                ])->cash_account_id),
                RemittanceLine::OUTCOME_WRITTEN_OFF => $this->settle($line, RemittanceLine::OUTCOME_WRITTEN_OFF, $actor, $note),
                default => throw ValidationException::withMessages(['outcome' => 'Say how it was settled.']),
            };

            return $line->fresh();
        });
    }

    /**
     * A short handover is covered first by the collector's credit — cash
     * they handed in above what was expected before (a payment that synced
     * late, an honest mistake). That cash is already in a till, so nothing
     * moves; the oldest credit goes first. Returns how much was covered.
     */
    private function coverFromCredit(Remittance $short, string $shortage): string
    {
        $covered = '0.00';

        $credits = Remittance::query()
            ->where('collector_user_id', $short->collector_user_id)
            ->whereKeyNot($short->id)
            ->where('credit_remaining', '>', 0)
            ->orderBy('received_at')
            ->lockForUpdate()
            ->get();

        foreach ($credits as $credit) {
            $left = bcsub($shortage, $covered, 2);

            if (bccomp($left, '0', 2) <= 0) {
                break;
            }

            $use = bccomp((string) $credit->credit_remaining, $left, 2) < 0 ? (string) $credit->credit_remaining : $left;
            $credit->update(['credit_remaining' => bcsub((string) $credit->credit_remaining, $use, 2)]);
            $covered = bcadd($covered, $use, 2);
        }

        return $covered;
    }

    /**
     * Cash taken at the desk by someone who holds a till goes straight into
     * it (owner, 2026-10-28): a handover made and received at once, so the
     * till's book and its day close count it, and it is never left as the
     * recorder's outstanding cash. The day close (counted against the book)
     * and the manager's pickup are its checks. Only cash — MoMo and bank
     * money is still ticked off its statement by someone else at a handover.
     * Returns null — and the payment goes through a handover as usual — when
     * it doesn't apply (not cash, no till, the till's day closed).
     */
    public function receiveAtDesk(Payment $payment, User $recorder): ?Remittance
    {
        if (($payment->method ?? 'cash') !== 'cash' || $payment->recorded_offline || ! $payment->isUnderCashControls()
            || ! app(TenantContext::class)->can('cash.receive')) {
            return null;
        }

        $till = $this->cashBook->tillFor($recorder);

        if ($this->cashBook->isDayClosed($till)) {
            return null;
        }

        return DB::transaction(function () use ($payment, $recorder, $till): Remittance {
            $amount = (string) $payment->amount;
            $now = now();

            $remittance = Remittance::query()->create([
                'number' => $this->documentNumbers->next(DocumentNumberService::REMITTANCE),
                'collector_user_id' => $recorder->id,
                'branch_id' => $till->branch_id,
                'business_date' => $this->cashBook->today(),
                'status' => 'received',
                'at_desk' => true,
                'expected_cash' => $amount,
                'expected_mtn' => '0',
                'expected_orange' => '0',
                'expected_bank' => '0',
                'declared_cash' => $amount,
                'submitted_at' => $now,
                'received_by' => $recorder->id,
                'received_at' => $now,
                'till_account_id' => $till->id,
                'received_cash' => $amount,
                'cash_variance' => '0',
                'bons_total' => '0',
            ]);

            RemittanceLine::query()->create([
                'remittance_id' => $remittance->id,
                'payment_id' => $payment->id,
                'method' => 'cash',
                'amount' => $amount,
            ]);

            $this->cashBook->record($till, 'in', $amount, CashMovement::KIND_DESK_PAYMENT, $recorder, [
                'document_number' => $remittance->number,
                'source' => $remittance,
                'note' => 'Desk payment — '.($payment->customer?->name ?? 'customer'),
            ]);

            return $remittance;
        });
    }

    /** Books a non-cash line into the branch's wallet or bank account. */
    private function bookFound(Remittance $remittance, RemittanceLine $line, User $actor, ?string $bankAccountUuid, ?string $note): void
    {
        // The collector's branch's account (branches v2 D3: no joint account).
        $account = $line->method === 'bank'
            ? $this->cashBook->bankFor($remittance->branch_id, $bankAccountUuid)
            : $this->cashBook->walletFor($line->method, $remittance->branch_id);

        $this->cashBook->record($account, 'in', (string) $line->amount, CashMovement::KIND_REMITTANCE_WALLET, $actor, [
            'document_number' => $remittance->number,
            'source' => $remittance,
            'reference' => $line->reference,
            'note' => $note,
        ]);

        $line->wallet_confirmed = true;
        $this->settle($line, RemittanceLine::OUTCOME_FOUND, $actor, $note, $account->id);
    }

    private function settle(RemittanceLine $line, string $outcome, User $actor, ?string $note, ?int $accountId = null): void
    {
        $line->fill([
            'outcome' => $outcome,
            'cash_account_id' => $accountId,
            'settled_by' => $actor->id,
            'settled_at' => now(),
            'settled_note' => $note,
        ])->save();
    }

    /** Money a collector repays goes into a till of the handover's own branch. */
    private function branchTill(Remittance $remittance, User $actor): CashAccount
    {
        $till = $this->cashBook->tillFor($actor);

        if ($till->branch_id !== $remittance->branch_id) {
            throw ValidationException::withMessages(['outcome' => "{$remittance->number} belongs to another branch — it is settled there."]);
        }

        return $till;
    }

    /** Nobody settles what they owe themselves — the owner included (owner, 2026-10-28). */
    private function assertNotOwnHandover(Remittance $remittance, User $actor, string $field = 'outcome'): void
    {
        if ($remittance->collector_user_id === $actor->id) {
            throw ValidationException::withMessages([$field => 'This is your own handover — someone else in the office deals with it.']);
        }
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
        $short = $remittance->shortage_status === 'open';

        if ($remittance->collector) {
            $this->notifications->broadcastToUser(
                $remittance->collector,
                'remittance.received',
                $short ? 'warning' : 'info',
                $short ? 'Handover short' : 'Handover received',
                "{$remittance->number}: {$remittance->receivedBy?->name} received {$money($remittance->received_cash)} cash"
                    .($short ? ", {$money($remittance->shortageOwed())} short of what you collected." : '.'),
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
