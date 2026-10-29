<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money between branches (branches v2 §4.7, D3, D6) — there is no joint
 * account, so a branch sends money with a recorded transfer:
 *
 *  - send:    out of the sender's account at once (it is no longer theirs to
 *             spend); the transfer is now in transit;
 *  - receive: the receiving branch puts it into one of its own accounts;
 *  - reject:  the receiving branch refuses it — it goes back into the
 *             sender's account.
 *
 * Both sides are cash-book movements (kind branch_transfer), so every
 * balance stays the sum of its movements; neither side is income or expense.
 */
class BranchTransferService
{
    public function __construct(
        private readonly CashBookService $cashBook,
        private readonly DocumentNumberService $documentNumbers,
        private readonly ShortageService $shortages,
    ) {}

    public function send(CashAccount $from, Branch $to, string $amount, ?string $note, User $actor): BranchTransfer
    {
        if ($from->branch_id === $to->id) {
            throw ValidationException::withMessages(['to_branch' => 'Choose another branch — within a branch, use a normal transfer between accounts.']);
        }

        // Nobody moves money out of someone else's drawer (owner, 2026-10-29).
        if ($from->isTill() && $from->holder_user_id !== $actor->id) {
            throw ValidationException::withMessages(['from_account' => "Only {$from->holder?->name} sends money out of their till."]);
        }

        return DB::transaction(function () use ($from, $to, $amount, $note, $actor): BranchTransfer {
            CashAccount::query()->withoutGlobalScope(BranchScope::class)->whereKey($from->id)->lockForUpdate()->first();

            if (bccomp($this->cashBook->balance($from), $amount, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => "{$from->name} only holds ".number_format((float) $this->cashBook->balance($from), 0, '.', ' ').' FCFA.',
                ]);
            }

            $transfer = BranchTransfer::query()->create([
                'number' => $this->documentNumbers->next(DocumentNumberService::BRANCH_TRANSFER),
                'from_branch_id' => $from->branch_id,
                'to_branch_id' => $to->id,
                'from_account_id' => $from->id,
                'amount' => $amount,
                'status' => BranchTransfer::STATUS_SENT,
                'note' => $note,
                'sent_by' => $actor->id,
                'sent_at' => now(),
            ]);

            $this->cashBook->record($from, 'out', $amount, CashMovement::KIND_BRANCH_TRANSFER, $actor, [
                'document_number' => $transfer->number,
                'source' => $transfer,
                'note' => "To {$to->name}".($note !== null ? ": {$note}" : ''),
            ]);

            return $transfer;
        });
    }

    /**
     * Puts what the receiving branch counted ($received, the whole amount
     * when not given) into one of its accounts; less than was sent is the
     * sender's shortage (owner, 2026-10-28). Never received by its sender.
     */
    public function receive(BranchTransfer $transfer, CashAccount $into, ?string $note, User $actor, ?string $received = null): BranchTransfer
    {
        return DB::transaction(function () use ($transfer, $into, $note, $actor, $received): BranchTransfer {
            $locked = $this->lockedInTransit($transfer);
            $this->assertNotSender($locked, $actor);
            $received ??= (string) $locked->amount;

            if (bccomp($received, '0', 2) <= 0 || bccomp($received, (string) $locked->amount, 2) > 0) {
                throw ValidationException::withMessages(['received_amount' => 'Type what you counted — more than nothing and no more than was sent (refuse it if nothing arrived).']);
            }

            if ($into->branch_id !== $locked->to_branch_id) {
                throw ValidationException::withMessages(['account' => 'Put the money into one of the receiving branch\'s own accounts.']);
            }

            $locked->update([
                'status' => BranchTransfer::STATUS_RECEIVED,
                'to_account_id' => $into->id,
                'received_by' => $actor->id,
                'received_at' => now(),
                'receive_note' => $note,
                'received_amount' => $received,
            ]);

            $short = bcsub((string) $locked->amount, $received, 2);

            if (bccomp($short, '0', 2) > 0) {
                $this->shortages->open($locked->sent_by, $locked->from_branch_id, $short, CashShortage::KIND_BRANCH_TRANSFER, $locked, $actor,
                    "{$locked->number}: received ".number_format((float) $received, 0, '.', ' ').' of '.number_format((float) $locked->amount, 0, '.', ' ').' FCFA');
            }

            $this->cashBook->record($into, 'in', $received, CashMovement::KIND_BRANCH_TRANSFER, $actor, [
                'document_number' => $locked->number,
                'source' => $locked,
                'note' => 'From '.$locked->fromBranch?->name.($note !== null ? ": {$note}" : ''),
            ]);

            return $locked;
        });
    }

    public function reject(BranchTransfer $transfer, string $reason, User $actor): BranchTransfer
    {
        return DB::transaction(function () use ($transfer, $reason, $actor): BranchTransfer {
            $locked = $this->lockedInTransit($transfer);
            $this->assertNotSender($locked, $actor);

            $locked->update([
                'status' => BranchTransfer::STATUS_REJECTED,
                'received_by' => $actor->id,
                'received_at' => now(),
                'receive_note' => $reason,
            ]);

            $this->cashBook->record($locked->fromAccount, 'in', (string) $locked->amount, CashMovement::KIND_BRANCH_TRANSFER_RETURN, $actor, [
                'document_number' => $locked->number,
                'source' => $locked,
                'note' => "Refused by {$locked->toBranch?->name}: {$reason}",
            ]);

            return $locked;
        });
    }

    /** Nobody receives or refuses money they sent themselves — the owner included. */
    private function assertNotSender(BranchTransfer $transfer, User $actor): void
    {
        if ($transfer->sent_by === $actor->id) {
            throw ValidationException::withMessages(['transfer' => 'You sent this transfer — someone at the receiving branch deals with it.']);
        }
    }

    private function lockedInTransit(BranchTransfer $transfer): BranchTransfer
    {
        $locked = BranchTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isInTransit()) {
            throw ValidationException::withMessages(['transfer' => "{$locked->number} was already {$locked->status}."]);
        }

        return $locked;
    }
}
