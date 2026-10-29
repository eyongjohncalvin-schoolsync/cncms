<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\CashTransfer;
use App\Models\CashTransferConfirmation;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money that waits for someone other than the sender (owner, 2026-10-28,
 * wave 3) — the only writer of App\Models\CashTransfer. Out of the sender's
 * account when sent; the open transfer is the money in transit.
 *
 * Handover (TR-…) — into another person's till, e.g. the manager's pickup:
 *  - accept: that person types what they counted; less than sent is the
 *    sender's shortage (whoever held the money last owns the gap);
 *  - refuse: back into the sender's till; recall: the sender takes it back
 *    while it is not accepted.
 *
 * Deposit (DP-…) — into a bank account, with the slip number and photo:
 *  - confirm: the company's `deposit_confirmations` people, never the
 *    depositor and each once, check the bank's own record; the last one
 *    credits the bank;
 *  - refuse: not (all) in the bank — what the bank shows is credited, the
 *    rest is the depositor's shortage.
 */
class CashTransferService
{
    public function __construct(
        private readonly CashBookService $cashBook,
        private readonly DocumentNumberService $documentNumbers,
        private readonly ShortageService $shortages,
    ) {}

    public function sendHandover(CashAccount $from, CashAccount $to, string $amount, User $actor, ?string $note): CashTransfer
    {
        $this->cashBook->assertCanMove($from, $to, $actor);

        if (! $to->isTill() || $to->holder_user_id === null || $to->holder_user_id === $actor->id) {
            throw ValidationException::withMessages(['to_account' => 'A handover goes into another person\'s till.']);
        }

        return DB::transaction(function () use ($from, $to, $amount, $actor, $note): CashTransfer {
            $this->cashBook->assertHolds($from, $amount);

            $transfer = CashTransfer::query()->create([
                'number' => $this->documentNumbers->next(DocumentNumberService::TRANSFER),
                'kind' => CashTransfer::KIND_HANDOVER,
                'branch_id' => $from->branch_id,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'to_user_id' => $to->holder_user_id,
                'amount' => $amount,
                'status' => CashTransfer::STATUS_SENT,
                'note' => $note,
                'sent_by' => $actor->id,
                'sent_at' => now(),
            ]);

            $this->cashBook->record($from, 'out', $amount, CashMovement::KIND_TRANSFER, $actor, [
                'document_number' => $transfer->number,
                'source' => $transfer,
                'counterpart' => $to,
                'note' => "Handed to {$to->holder?->name} — waiting for them to accept".($note !== null ? ": {$note}" : ''),
            ]);

            return $transfer;
        });
    }

    public function acceptHandover(CashTransfer $transfer, string $counted, User $actor, ?string $note): CashTransfer
    {
        if ($transfer->kind !== CashTransfer::KIND_HANDOVER || $transfer->to_user_id !== $actor->id) {
            throw ValidationException::withMessages(['counted' => 'Only the person it was handed to accepts it.']);
        }

        if (bccomp($counted, '0', 2) <= 0) {
            throw ValidationException::withMessages(['counted' => 'Nothing counted — refuse the handover instead.']);
        }

        if (bccomp($counted, (string) $transfer->amount, 2) > 0) {
            throw ValidationException::withMessages(['counted' => 'You counted more than was handed over — count again, or refuse it and ask for a new handover.']);
        }

        return DB::transaction(function () use ($transfer, $counted, $actor, $note): CashTransfer {
            $locked = $this->lockedInTransit($transfer);

            $this->cashBook->record($locked->toAccount, 'in', $counted, CashMovement::KIND_TRANSFER, $actor, [
                'document_number' => $locked->number,
                'source' => $locked,
                'counterpart' => $locked->fromAccount,
                'note' => 'Handover from '.($locked->sentBy?->name ?? 'a colleague').($note !== null ? ": {$note}" : ''),
            ]);

            $short = bcsub((string) $locked->amount, $counted, 2);

            if (bccomp($short, '0', 2) > 0) {
                $this->shortages->open($locked->sent_by, $locked->branch_id, $short, CashShortage::KIND_HANDOVER, $locked, $actor,
                    "{$locked->number}: {$actor->name} counted ".number_format((float) $counted, 0, '.', ' ').' of '.number_format((float) $locked->amount, 0, '.', ' ').' FCFA');
            }

            $locked->update([
                'status' => CashTransfer::STATUS_RECEIVED,
                'received_amount' => $counted,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $locked;
        });
    }

    public function refuseHandover(CashTransfer $transfer, string $reason, User $actor): CashTransfer
    {
        if ($transfer->kind !== CashTransfer::KIND_HANDOVER || $transfer->to_user_id !== $actor->id) {
            throw ValidationException::withMessages(['reason' => 'Only the person it was handed to refuses it.']);
        }

        return $this->giveBack($transfer, CashTransfer::STATUS_REJECTED, $actor, "Refused by {$actor->name}: {$reason}", $reason);
    }

    public function recall(CashTransfer $transfer, User $actor): CashTransfer
    {
        if ($transfer->kind !== CashTransfer::KIND_HANDOVER || $transfer->sent_by !== $actor->id) {
            throw ValidationException::withMessages(['transfer' => 'Only whoever handed it over takes it back.']);
        }

        return $this->giveBack($transfer, CashTransfer::STATUS_RECALLED, $actor, 'Taken back before it was accepted', null);
    }

    public function sendDeposit(CashAccount $from, CashAccount $bank, string $amount, string $slipNumber, UploadedFile $slipPhoto, User $actor, ?string $note): CashTransfer
    {
        $this->cashBook->assertCanMove($from, $bank, $actor);

        if ($bank->kind !== CashAccount::KIND_BANK) {
            throw ValidationException::withMessages(['to_account' => 'A deposit goes into a bank account.']);
        }

        $slipNumber = trim($slipNumber);
        $hash = (string) hash_file('sha256', $slipPhoto->getRealPath());

        $used = CashTransfer::query()->where('kind', CashTransfer::KIND_DEPOSIT)->where('status', '!=', CashTransfer::STATUS_REJECTED);

        if ((clone $used)->where('to_account_id', $bank->id)->whereRaw('lower(slip_number) = ?', [mb_strtolower($slipNumber)])->exists()) {
            throw ValidationException::withMessages(['slip_number' => 'This slip number is already recorded for this bank account.']);
        }

        if ((clone $used)->where('slip_photo_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['slip_photo' => 'This photo was already used for another deposit — take a photo of this deposit\'s slip.']);
        }

        return DB::transaction(function () use ($from, $bank, $amount, $slipNumber, $slipPhoto, $hash, $actor, $note): CashTransfer {
            $this->cashBook->assertHolds($from, $amount);

            $transfer = CashTransfer::query()->create([
                'number' => $this->documentNumbers->next(DocumentNumberService::DEPOSIT),
                'kind' => CashTransfer::KIND_DEPOSIT,
                'branch_id' => $from->branch_id,
                'from_account_id' => $from->id,
                'to_account_id' => $bank->id,
                'amount' => $amount,
                'status' => CashTransfer::STATUS_SENT,
                'slip_number' => $slipNumber,
                'slip_photo_path' => $slipPhoto->store('deposit-slips', $this->disk()),
                'slip_photo_hash' => $hash,
                'required_confirmations' => self::confirmationsNeeded(),
                'note' => $note,
                'sent_by' => $actor->id,
                'sent_at' => now(),
            ]);

            $this->cashBook->record($from, 'out', $amount, CashMovement::KIND_DEPOSIT, $actor, [
                'document_number' => $transfer->number,
                'source' => $transfer,
                'counterpart' => $bank,
                'reference' => $slipNumber,
                'note' => 'Deposited — in transit until confirmed against the bank'.($note !== null ? ": {$note}" : ''),
            ]);

            return $transfer;
        });
    }

    public function confirmDeposit(CashTransfer $transfer, User $actor): CashTransfer
    {
        $this->assertMayCheckDeposit($transfer, $actor);

        return DB::transaction(function () use ($transfer, $actor): CashTransfer {
            $locked = $this->lockedInTransit($transfer);

            if ($locked->confirmations()->where('user_id', $actor->id)->exists()) {
                throw ValidationException::withMessages(['deposit' => 'You already confirmed this deposit — someone else confirms it next.']);
            }

            CashTransferConfirmation::query()->create(['cash_transfer_id' => $locked->id, 'user_id' => $actor->id]);

            if ($locked->confirmations()->count() >= $locked->required_confirmations) {
                $this->cashBook->record($locked->toAccount, 'in', (string) $locked->amount, CashMovement::KIND_DEPOSIT, $actor, [
                    'document_number' => $locked->number,
                    'source' => $locked,
                    'counterpart' => $locked->fromAccount,
                    'reference' => $locked->slip_number,
                    'note' => 'Deposit confirmed against the bank',
                ]);

                $locked->update([
                    'status' => CashTransfer::STATUS_RECEIVED,
                    'received_amount' => $locked->amount,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ]);
            }

            return $locked;
        });
    }

    /** The bank does not show it — or shows only $found of it: that much is credited, the rest is the depositor's shortage. */
    public function refuseDeposit(CashTransfer $transfer, string $found, string $reason, User $actor): CashTransfer
    {
        $this->assertMayCheckDeposit($transfer, $actor);

        if (bccomp($found, '0', 2) < 0 || bccomp($found, (string) $transfer->amount, 2) >= 0) {
            throw ValidationException::withMessages(['found' => 'Type what the bank shows — less than the deposit (0 if nothing). If it is all there, confirm it.']);
        }

        return DB::transaction(function () use ($transfer, $found, $reason, $actor): CashTransfer {
            $locked = $this->lockedInTransit($transfer);

            if (bccomp($found, '0', 2) > 0) {
                $this->cashBook->record($locked->toAccount, 'in', $found, CashMovement::KIND_DEPOSIT, $actor, [
                    'document_number' => $locked->number,
                    'source' => $locked,
                    'counterpart' => $locked->fromAccount,
                    'reference' => $locked->slip_number,
                    'note' => "Only part of the deposit is in the bank: {$reason}",
                ]);
            }

            $this->shortages->open($locked->sent_by, $locked->branch_id, bcsub((string) $locked->amount, $found, 2), CashShortage::KIND_DEPOSIT, $locked, $actor,
                "{$locked->number} (slip {$locked->slip_number}): {$reason}");

            $locked->update([
                'status' => CashTransfer::STATUS_REJECTED,
                'received_amount' => $found,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $reason,
            ]);

            return $locked;
        });
    }

    /** How many people confirm a new deposit: the company's setting, 1 or 2 (owner: "a 2nd and even 3rd person"). */
    public static function confirmationsNeeded(): int
    {
        return max(1, min(2, (int) (Company::cached()?->deposit_confirmations ?? 2)));
    }

    public function disk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    private function assertMayCheckDeposit(CashTransfer $transfer, User $actor): void
    {
        if (! $transfer->isDeposit()) {
            throw ValidationException::withMessages(['deposit' => 'This is not a bank deposit.']);
        }

        if ($transfer->sent_by === $actor->id) {
            throw ValidationException::withMessages(['deposit' => 'You made this deposit — someone else checks it against the bank.']);
        }

        if (! app(TenantContext::class)->can('cash.view')) {
            throw ValidationException::withMessages(['deposit' => 'Only someone who sees the cash book checks deposits.']);
        }
    }

    private function giveBack(CashTransfer $transfer, string $status, User $actor, string $movementNote, ?string $decisionNote): CashTransfer
    {
        return DB::transaction(function () use ($transfer, $status, $actor, $movementNote, $decisionNote): CashTransfer {
            $locked = $this->lockedInTransit($transfer);

            // Back into the day it left on: that day cannot close while the
            // handover is open (CashBookService::closeDay()), so it is always open.
            $sentOn = CashMovement::query()->where('source_type', $locked->getMorphClass())->where('source_id', $locked->id)->where('direction', 'out')->value('business_date');

            $this->cashBook->record($locked->fromAccount, 'in', (string) $locked->amount, CashMovement::KIND_TRANSFER, $actor, [
                'business_date' => $sentOn !== null ? Carbon::parse($sentOn)->toDateString() : $this->cashBook->today(),
                'document_number' => $locked->number,
                'source' => $locked,
                'counterpart' => $locked->toAccount,
                'note' => $movementNote,
            ]);

            $locked->update([
                'status' => $status,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
            ]);

            return $locked;
        });
    }

    private function lockedInTransit(CashTransfer $transfer): CashTransfer
    {
        $locked = CashTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isInTransit()) {
            throw ValidationException::withMessages(['transfer' => "{$locked->number} was already {$locked->status}."]);
        }

        return $locked;
    }
}
