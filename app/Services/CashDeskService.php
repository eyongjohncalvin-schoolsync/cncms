<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashClose;
use App\Models\CashDeposit;
use App\Models\CashHandover;
use App\Models\CashLedgerEntry;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The cash desk's workflows on top of the ledger:
 *
 * - handovers: the giver starts one (amount + code), the receiver confirms
 *   what they counted on their own phone. Short → only the received
 *   amount moves; the rest stays the giver's. Nobody confirms their own.
 * - daily close: a blind count by denomination. Up to the auto-tolerance
 *   (500 FCFA) a difference is settled automatically; above it a manager
 *   decides; above 10,000 FCFA, or on a third shortage in 30 days, only the
 *   owner. Settling posts the difference so the ledger matches the cash.
 * - deposits: cash to the bank / MoMo with the slip photo, confirmed (or
 *   rejected, which reverses it) by a second person.
 */
class CashDeskService
{
    public const string AUTO_TOLERANCE = '500.00';

    public const string OWNER_THRESHOLD = '10000.00';

    /** Notes and coins in circulation (FCFA). */
    public const array DENOMINATIONS = [10000, 5000, 2000, 1000, 500, 100, 50, 25, 10, 5, 1];

    public function __construct(
        private readonly CashLedgerService $ledger,
        private readonly TenantContext $context,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Everything the "My cash" card and screen need for one person.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(User $user): array
    {
        $till = $this->ledger->tillFor($user);
        $start = Carbon::now('Africa/Douala')->startOfDay()->utc();

        // Today's movements by type, one grouped query.
        $today = CashLedgerEntry::query()
            ->where('account_id', $till->id)
            ->where('occurred_at', '>=', $start)
            ->groupBy('type', 'direction')
            ->selectRaw('type, direction, sum(amount) as total')
            ->get();

        $sum = fn (array $types): string => bcadd((string) $today->whereIn('type', $types)->sum('total'), '0', 2);

        $lastClose = CashClose::query()->where('account_id', $till->id)->latest('business_date')->first();

        return [
            'account' => $this->accountRow($till),
            'today' => [
                'collected' => $sum([CashLedgerEntry::TYPE_COLLECTION]),
                'spent' => $sum([CashLedgerEntry::TYPE_EXPENSE]),
                'handed_over' => $sum([CashLedgerEntry::TYPE_HANDOVER_OUT, CashLedgerEntry::TYPE_DEPOSIT_OUT]),
                'received' => $sum([CashLedgerEntry::TYPE_HANDOVER_IN]),
            ],
            'last_close' => $lastClose === null ? null : $this->closeRow($lastClose, revealExpected: true),
            'closed_today' => $lastClose?->business_date?->isSameDay(Carbon::now('Africa/Douala')) ?? false,
            'over_max_hold' => $till->max_hold !== null && bccomp((string) $till->balance, (string) $till->max_hold, 2) > 0,
            'incoming' => $this->incomingFor($user)->map(fn (CashHandover $handover): array => $this->handoverRow($handover))->values()->all(),
            'outgoing' => CashHandover::query()->with(['fromAccount', 'toAccount.holder', 'initiator'])
                ->where('from_account_id', $till->id)->where('status', CashHandover::STATUS_PENDING)
                ->latest()->get()
                ->map(fn (CashHandover $handover): array => $this->handoverRow($handover, withCode: true))->values()->all(),
            'denominations' => self::DENOMINATIONS,
        ];
    }

    /**
     * Accounts this person can hand cash to: the safe, petty cash and
     * other people's tills (never their own).
     *
     * @return Collection<int, CashAccount>
     */
    public function handoverTargets(User $user): Collection
    {
        return CashAccount::query()
            ->with('holder')
            ->where('is_active', true)
            ->whereIn('type', CashAccount::CASH_TYPES)
            ->where(fn ($query) => $query->whereNull('holder_user_id')->orWhere('holder_user_id', '!=', $user->id))
            ->whereNotNull('holder_user_id')
            ->orderByRaw("case type when 'safe' then 0 when 'petty_cash' then 1 else 2 end")
            ->orderBy('name')
            ->get();
    }

    /**
     * Handovers waiting for this person to confirm (accounts they hold).
     *
     * @return Collection<int, CashHandover>
     */
    public function incomingFor(User $user): Collection
    {
        return CashHandover::query()
            ->with(['fromAccount.holder', 'toAccount', 'initiator'])
            ->where('status', CashHandover::STATUS_PENDING)
            ->whereIn('to_account_id', CashAccount::query()->where('holder_user_id', $user->id)->select('id'))
            ->oldest()
            ->get();
    }

    /**
     * @param  array<string, int>|null  $denominations
     *
     * @throws ValidationException
     */
    public function startHandover(User $giver, CashAccount $to, string $amount, ?array $denominations = null, ?string $note = null, ?string $localUuid = null, ?CashAccount $from = null): CashHandover
    {
        if ($localUuid !== null && ($existing = CashHandover::query()->where('local_uuid', $localUuid)->first()) !== null) {
            return $existing;
        }

        $from ??= $this->ledger->tillFor($giver);

        if ($from->holder_user_id !== $giver->id && ! $this->context->can('cash.manage')) {
            throw ValidationException::withMessages(['from' => ['You can only hand over cash you hold.']]);
        }

        if ($from->id === $to->id || ! $to->is_active || ! $to->isCash() || $to->holder_user_id === null) {
            throw ValidationException::withMessages(['to' => ['Choose who is receiving the cash.']]);
        }

        if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, (string) $from->balance, 2) > 0) {
            throw ValidationException::withMessages(['amount' => ['You hold '.number_format((float) $from->balance, 0, '.', ',').' FCFA — you can hand over up to that.']]);
        }

        $this->assertDenominationsMatch($denominations, $amount);

        $handover = CashHandover::query()->create([
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => $amount,
            'denominations' => $denominations,
            'status' => CashHandover::STATUS_PENDING,
            'code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'note' => $note,
            'initiated_by' => $giver->id,
            'initiated_at' => Carbon::now(),
            'local_uuid' => $localUuid,
        ]);

        if ($to->holder !== null) {
            $this->notifications->broadcastToUser(
                $to->holder, 'cash.handover_incoming', 'info', 'Cash coming to you',
                "{$giver->name} is handing you ".number_format((float) $amount, 0, '.', ',').' FCFA. Count it, then confirm with the code on their phone.',
                '/cash', 'cash_handover', $handover->uuid,
            );
        }

        return $handover;
    }

    /**
     * The receiver confirms what they counted. The code (shown on the
     * giver's phone) proves both people were together.
     *
     * @param  array<string, int>|null  $denominations
     *
     * @throws ValidationException
     */
    public function confirmHandover(CashHandover $handover, User $receiver, string $code, string $receivedAmount, ?array $denominations = null): CashHandover
    {
        return DB::transaction(function () use ($handover, $receiver, $code, $receivedAmount, $denominations): CashHandover {
            $handover = CashHandover::query()->with(['fromAccount', 'toAccount'])->whereKey($handover->id)->lockForUpdate()->firstOrFail();

            if ($handover->status !== CashHandover::STATUS_PENDING) {
                throw ValidationException::withMessages(['handover' => ['This handover is no longer waiting.']]);
            }

            if ($handover->toAccount->holder_user_id !== $receiver->id) {
                throw ValidationException::withMessages(['handover' => ['Only the person receiving the cash can confirm it.']]);
            }

            if (! hash_equals($handover->code, trim($code))) {
                throw ValidationException::withMessages(['code' => ['That code does not match — read it from the giver\'s phone.']]);
            }

            $received = bcadd($receivedAmount, '0', 2);

            if (bccomp($received, '0', 2) <= 0 || bccomp($received, (string) $handover->amount, 2) > 0) {
                throw ValidationException::withMessages(['received_amount' => ['Count again: you can receive at most what was handed over.']]);
            }

            $this->assertDenominationsMatch($denominations, $received);

            $source = ['type' => 'handover', 'id' => $handover->id];
            $note = "Handover {$handover->fromAccount->name} → {$handover->toAccount->name}";

            $this->ledger->post($handover->fromAccount, CashLedgerEntry::OUT, $received, CashLedgerEntry::TYPE_HANDOVER_OUT, $source, null, $receiver, $note);
            $this->ledger->post($handover->toAccount, CashLedgerEntry::IN, $received, CashLedgerEntry::TYPE_HANDOVER_IN, $source, null, $receiver, $note);

            $short = bccomp($received, (string) $handover->amount, 2) < 0;

            $handover->forceFill([
                'status' => $short ? CashHandover::STATUS_SHORT : CashHandover::STATUS_CONFIRMED,
                'received_amount' => $received,
                'confirmed_by' => $receiver->id,
                'confirmed_at' => Carbon::now(),
            ])->save();

            if ($short) {
                $this->notifications->broadcastToRole(
                    'manager', 'cash.handover_short', 'warning', 'Cash handover arrived short',
                    "{$handover->fromAccount->name} declared ".number_format((float) $handover->amount, 0, '.', ',')." FCFA; {$receiver->name} counted ".number_format((float) $received, 0, '.', ',').' FCFA.',
                    '/cash', 'cash_handover', $handover->uuid,
                );
            }

            return $handover;
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancelHandover(CashHandover $handover, User $actor): CashHandover
    {
        if ($handover->status !== CashHandover::STATUS_PENDING) {
            throw ValidationException::withMessages(['handover' => ['This handover is no longer waiting.']]);
        }

        if ($handover->initiated_by !== $actor->id && ! $this->context->can('cash.manage')) {
            throw ValidationException::withMessages(['handover' => ['Only the person who started it can cancel it.']]);
        }

        $handover->forceFill(['status' => CashHandover::STATUS_CANCELLED])->save();

        return $handover;
    }

    /**
     * The daily blind count. Returns the close with the expected figure
     * revealed only now.
     *
     * @param  array<string, int>  $denominations  note/coin value => count
     *
     * @throws ValidationException
     */
    public function submitClose(User $user, array $denominations, ?string $note = null, ?CashAccount $account = null): CashClose
    {
        $account ??= $this->ledger->tillFor($user);

        if ($account->holder_user_id !== $user->id) {
            throw ValidationException::withMessages(['account' => ['You can only count cash you hold.']]);
        }

        $counted = $this->countDenominations($denominations);
        $businessDate = Carbon::now('Africa/Douala')->toDateString();

        return DB::transaction(function () use ($account, $user, $denominations, $counted, $note, $businessDate): CashClose {
            $existing = CashClose::query()->where('account_id', $account->id)->where('business_date', $businessDate)->lockForUpdate()->first();

            if ($existing !== null && $existing->status !== CashClose::STATUS_REJECTED) {
                throw ValidationException::withMessages(['close' => ['Today is already closed for this cash.']]);
            }

            $expected = bcadd((string) CashAccount::query()->whereKey($account->id)->value('balance'), '0', 2);
            $variance = bcsub($counted, $expected, 2);

            $close = $existing ?? new CashClose(['account_id' => $account->id, 'business_date' => $businessDate]);
            $close->fill([
                'expected' => $expected,
                'counted' => $counted,
                'variance' => $variance,
                'denominations' => $this->cleanDenominations($denominations),
                'status' => CashClose::STATUS_PENDING,
                'note' => $note,
                'closed_by' => $user->id,
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
            ]);
            $close->save();

            if (bccomp($variance, '0', 2) === 0) {
                $close->forceFill(['status' => CashClose::STATUS_BALANCED, 'decided_at' => Carbon::now()])->save();
            } elseif (bccomp($this->abs($variance), self::AUTO_TOLERANCE, 2) <= 0) {
                $this->settle($close, null, 'Within the automatic tolerance');
            } else {
                $this->notifications->broadcastToRole(
                    $this->needsOwner($close) ? 'super' : 'manager',
                    'cash.close_difference', 'warning', 'Cash count difference to approve',
                    "{$account->name}: counted ".number_format((float) $counted, 0, '.', ',').' FCFA, expected '.number_format((float) $expected, 0, '.', ',').' FCFA.',
                    '/cash', 'cash_close', $close->uuid,
                );
            }

            return $close;
        });
    }

    /**
     * Whether settling this close difference needs the owner: a big
     * difference, or a third shortage on this account in 30 days.
     */
    public function needsOwner(CashClose $close): bool
    {
        if (bccomp($this->abs((string) $close->variance), self::OWNER_THRESHOLD, 2) > 0) {
            return true;
        }

        if (bccomp((string) $close->variance, '0', 2) >= 0) {
            return false;
        }

        $earlierShortages = CashClose::query()
            ->where('account_id', $close->account_id)
            ->whereKeyNot($close->id)
            ->where('variance', '<', 0)
            ->whereIn('status', [CashClose::STATUS_APPROVED, CashClose::STATUS_PENDING])
            ->where('business_date', '>=', Carbon::parse($close->business_date)->subDays(30))
            ->count();

        return $earlierShortages >= 2;
    }

    /**
     * @throws ValidationException
     */
    public function decideClose(CashClose $close, User $actor, bool $approve, ?string $note = null): CashClose
    {
        if ($close->status !== CashClose::STATUS_PENDING) {
            throw ValidationException::withMessages(['close' => ['This count is not waiting for a decision.']]);
        }

        if ($close->closed_by === $actor->id) {
            throw ValidationException::withMessages(['close' => ["You can't approve your own cash count."]]);
        }

        if ($approve) {
            $this->settle($close, $actor, $note);
        } else {
            $close->forceFill([
                'status' => CashClose::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ])->save();
        }

        return $close;
    }

    /**
     * @throws ValidationException
     */
    public function deposit(User $user, CashAccount $from, CashAccount $to, string $amount, ?string $reference, ?UploadedFile $slip): CashDeposit
    {
        if (! $from->isCash() || $to->isCash()) {
            throw ValidationException::withMessages(['to' => ['A deposit moves cash into the bank or a mobile-money account.']]);
        }

        if ($from->holder_user_id !== $user->id && ! $this->context->can('cash.manage')) {
            throw ValidationException::withMessages(['from' => ['You can only deposit cash you hold.']]);
        }

        return DB::transaction(function () use ($user, $from, $to, $amount, $reference, $slip): CashDeposit {
            $deposit = CashDeposit::query()->create([
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'reference' => $reference,
                'slip_path' => $slip?->store('cash/deposit-slips', 'local'),
                'slip_sha256' => $slip !== null ? (hash_file('sha256', $slip->getRealPath()) ?: null) : null,
                'status' => CashDeposit::STATUS_PENDING,
                'deposited_by' => $user->id,
            ]);

            $source = ['type' => 'deposit', 'id' => $deposit->id];
            $this->ledger->post($from, CashLedgerEntry::OUT, $amount, CashLedgerEntry::TYPE_DEPOSIT_OUT, $source, null, $user, $reference);
            $this->ledger->post($to, CashLedgerEntry::IN, $amount, CashLedgerEntry::TYPE_DEPOSIT_IN, $source, null, $user, $reference);

            return $deposit;
        });
    }

    /**
     * @throws ValidationException
     */
    public function decideDeposit(CashDeposit $deposit, User $actor, bool $confirm, ?string $note = null): CashDeposit
    {
        if ($deposit->status !== CashDeposit::STATUS_PENDING) {
            throw ValidationException::withMessages(['deposit' => ['This deposit is not waiting for a decision.']]);
        }

        if ($deposit->deposited_by === $actor->id) {
            throw ValidationException::withMessages(['deposit' => ["You can't confirm your own deposit."]]);
        }

        return DB::transaction(function () use ($deposit, $actor, $confirm, $note): CashDeposit {
            if (! $confirm) {
                $this->ledger->reverseSource('deposit', $deposit->id, $actor, 'Deposit rejected: '.($note ?? ''));
            }

            $deposit->forceFill([
                'status' => $confirm ? CashDeposit::STATUS_CONFIRMED : CashDeposit::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ])->save();

            return $deposit;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function accountRow(CashAccount $account): array
    {
        return [
            'uuid' => $account->uuid,
            'type' => $account->type,
            'name' => $account->name,
            'holder' => $account->relationLoaded('holder') && $account->holder ? ['uuid' => $account->holder->uuid, 'name' => $account->holder->name] : null,
            'balance' => (string) $account->balance,
            'max_hold' => $account->max_hold !== null ? (string) $account->max_hold : null,
            'is_active' => $account->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function handoverRow(CashHandover $handover, bool $withCode = false): array
    {
        return [
            'uuid' => $handover->uuid,
            'from' => $handover->fromAccount?->name,
            'to' => $handover->toAccount?->name,
            'amount' => (string) $handover->amount,
            'received_amount' => $handover->received_amount !== null ? (string) $handover->received_amount : null,
            'status' => $handover->status,
            'note' => $handover->note,
            'initiated_by' => $handover->initiator?->name,
            'initiated_at' => $handover->initiated_at?->toIso8601String(),
            'confirmed_at' => $handover->confirmed_at?->toIso8601String(),
            'code' => $withCode ? $handover->code : null,
        ];
    }

    /**
     * The expected figure is only shown once the count is in (blind count).
     *
     * @return array<string, mixed>
     */
    public function closeRow(CashClose $close, bool $revealExpected = true): array
    {
        return [
            'uuid' => $close->uuid,
            'account' => $close->account?->name,
            'business_date' => $close->business_date?->toDateString(),
            'counted' => (string) $close->counted,
            'expected' => $revealExpected ? (string) $close->expected : null,
            'variance' => $revealExpected ? (string) $close->variance : null,
            'denominations' => $close->denominations,
            'status' => $close->status,
            'note' => $close->note,
            'closed_by' => $close->closer?->name,
            'decided_by' => $close->decider?->name,
            'decision_note' => $close->decision_note,
            'needs_owner' => $close->status === CashClose::STATUS_PENDING && $this->needsOwner($close),
        ];
    }

    /**
     * Posts the close difference so the ledger matches the counted cash.
     */
    private function settle(CashClose $close, ?User $actor, ?string $note): void
    {
        $variance = (string) $close->variance;
        $short = bccomp($variance, '0', 2) < 0;

        $this->ledger->post(
            $close->account,
            $short ? CashLedgerEntry::OUT : CashLedgerEntry::IN,
            $this->abs($variance),
            $short ? CashLedgerEntry::TYPE_CLOSE_SHORT : CashLedgerEntry::TYPE_CLOSE_OVER,
            ['type' => 'close', 'id' => $close->id],
            null,
            $actor,
            $note,
            allowNegative: true,
        );

        $close->forceFill([
            'status' => CashClose::STATUS_APPROVED,
            'decided_by' => $actor?->id,
            'decided_at' => Carbon::now(),
            'decision_note' => $note,
        ])->save();
    }

    /**
     * @param  array<string, int>|null  $denominations
     *
     * @throws ValidationException
     */
    private function assertDenominationsMatch(?array $denominations, string $amount): void
    {
        if ($denominations === null || $denominations === []) {
            return;
        }

        if (bccomp($this->countDenominations($denominations), $amount, 2) !== 0) {
            throw ValidationException::withMessages(['denominations' => ['The notes and coins counted do not add up to the amount.']]);
        }
    }

    /**
     * @param  array<string|int, int|string>  $denominations
     */
    private function countDenominations(array $denominations): string
    {
        $total = '0';

        foreach ($this->cleanDenominations($denominations) as $value => $count) {
            $total = bcadd($total, bcmul((string) $value, (string) $count, 0), 0);
        }

        return bcadd($total, '0', 2);
    }

    /**
     * Keeps only real FCFA denominations with a positive count.
     *
     * @param  array<string|int, int|string>  $denominations
     * @return array<int, int>
     */
    private function cleanDenominations(array $denominations): array
    {
        $clean = [];

        foreach ($denominations as $value => $count) {
            if (in_array((int) $value, self::DENOMINATIONS, true) && (int) $count > 0) {
                $clean[(int) $value] = (int) $count;
            }
        }

        krsort($clean);

        return $clean;
    }

    private function abs(string $value): string
    {
        return ltrim($value, '-');
    }
}
