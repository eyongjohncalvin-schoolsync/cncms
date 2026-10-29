<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owned shortages (owner, 2026-10-28, wave 3) — the only writer of
 * App\Models\CashShortage. Whoever last held the money owns what is
 * missing, and it stays open until someone else settles it:
 *
 *  - `repaid`      — the owner pays it in cash into the settler's till
 *                    (`cash.receive`);
 *  - `written_off` — a manager accepts the loss, with a reason
 *                    (`vouchers.approve`).
 *
 * Nobody settles their own shortage — the owner of the company included.
 */
class ShortageService
{
    public const array OUTCOMES = [CashShortage::STATUS_REPAID, CashShortage::STATUS_WRITTEN_OFF];

    public function __construct(
        private readonly CashBookService $cashBook,
    ) {}

    public function open(int $ownerUserId, ?int $branchId, string $amount, string $kind, ?Model $source, User $actor, ?string $note = null): CashShortage
    {
        return CashShortage::query()->create([
            'branch_id' => $branchId,
            'owner_user_id' => $ownerUserId,
            'kind' => $kind,
            'amount' => $amount,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'note' => $note,
            'status' => CashShortage::STATUS_OPEN,
            'created_by' => $actor->id,
        ]);
    }

    public function settle(CashShortage $shortage, string $outcome, User $actor, ?string $note): CashShortage
    {
        if ($shortage->owner_user_id === $actor->id) {
            throw ValidationException::withMessages(['outcome' => 'This is your own shortage — someone else in the office settles it.']);
        }

        $context = app(TenantContext::class);
        $allowed = $outcome === CashShortage::STATUS_REPAID ? $context->can('cash.receive') : $context->can('vouchers.approve');

        if (! $allowed) {
            throw ValidationException::withMessages(['outcome' => $outcome === CashShortage::STATUS_REPAID
                ? 'Only someone with a till takes the repayment.'
                : 'Only a manager writes a shortage off.']);
        }

        if ($outcome === CashShortage::STATUS_WRITTEN_OFF && ($note === null || trim($note) === '')) {
            throw ValidationException::withMessages(['note' => 'Say why the shortage is written off.']);
        }

        return DB::transaction(function () use ($shortage, $outcome, $actor, $note): CashShortage {
            $locked = CashShortage::query()->whereKey($shortage->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['outcome' => 'This shortage is already settled.']);
            }

            if ($outcome === CashShortage::STATUS_REPAID) {
                $till = $this->cashBook->tillFor($actor);

                if ($locked->branch_id !== null && $till->branch_id !== $locked->branch_id) {
                    throw ValidationException::withMessages(['outcome' => 'This shortage belongs to another branch — it is repaid there.']);
                }

                $this->cashBook->record($till, 'in', (string) $locked->amount, CashMovement::KIND_SHORTAGE_REPAYMENT, $actor, [
                    'source' => $locked,
                    'note' => 'Shortage repaid by '.($locked->owner?->name ?? 'its owner').($note ? ": {$note}" : ''),
                ]);
            }

            $locked->update([
                'status' => $outcome,
                'settled_by' => $actor->id,
                'settled_at' => now(),
                'settled_note' => $note,
            ]);

            return $locked;
        });
    }
}
