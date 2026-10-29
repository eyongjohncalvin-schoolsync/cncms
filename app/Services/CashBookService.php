<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\CashAccount;
use App\Models\CashDayClose;
use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\CashStatementCount;
use App\Models\CashTransfer;
use App\Models\Remittance;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Support\BranchAttribution;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The cash book (docs/plans/cash-controls.md, wave 4) — the only writer of
 * CashMovement rows. Every franc that moves between company accounts is one
 * movement per side, dated to the business day, recorded by a named user;
 * balances are always computed from the movements, never stored.
 *
 * A till whose day is closed refuses new movements for that day until a
 * supervisor reopens it with a reason.
 */
class CashBookService
{
    /** CFA notes and coins a count may list. */
    public const array DENOMINATIONS = [10000, 5000, 2000, 1000, 500, 100, 50, 25, 10, 5];

    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    public function today(): string
    {
        return BusinessTimezone::today();
    }

    /**
     * The cashier's own till, created the first time they need it. A
     * person's till is theirs whichever branch is being viewed (branches v2).
     */
    public function tillFor(User $user): CashAccount
    {
        $branchId = BranchAttribution::forUser($user);
        $till = $this->activeTillOf($user);

        // A cashier transferred to another branch: their emptied till stays,
        // closed, in the old branch's books and a new one opens here. (A till
        // still holding money blocks the transfer — UserController.) A till
        // with a branch transfer still in transit stays open: a refusal must
        // be able to put the money back into it.
        if ($till !== null && $till->branch_id !== $branchId && bccomp($this->balance($till), '0', 2) === 0
            && ! BranchTransfer::query()->where('from_account_id', $till->id)->where('status', BranchTransfer::STATUS_SENT)->exists()
            && ! CashTransfer::query()->withoutGlobalScope(BranchScope::class)->inTransit()->where('from_account_id', $till->id)->exists()) {
            $till->update(['is_active' => false]);
            $till = null;
        }

        return $till ?? CashAccount::query()->create([
            'kind' => CashAccount::KIND_TILL,
            'holder_user_id' => $user->id,
            'name' => "Till — {$user->name}",
            'is_active' => true,
            'branch_id' => $branchId,
        ]);
    }

    /** The person's open till, whichever branch it is in. */
    public function activeTillOf(User $user): ?CashAccount
    {
        return CashAccount::query()->withoutGlobalScope(BranchScope::class)
            ->where('kind', CashAccount::KIND_TILL)->where('holder_user_id', $user->id)->where('is_active', true)
            ->first();
    }

    /**
     * A branch's wallet for a mobile-money method (mtn_momo / orange_money) —
     * each branch keeps its own money (branches v2 D3); headquarters' when no
     * branch is given. Found whichever branch the caller works in, and created
     * the first time a branch needs it.
     */
    public function walletFor(string $paymentMethod, ?int $branchId = null): CashAccount
    {
        $provider = CashAccount::WALLET_PROVIDERS[$paymentMethod];
        $branchId ??= Branch::headquartersId();
        $branch = Branch::allMemoized()->get($branchId);
        $name = $provider === 'mtn' ? 'MTN MoMo wallet' : 'Orange Money wallet';

        return CashAccount::query()->withoutGlobalScope(BranchScope::class)->firstOrCreate(
            ['kind' => CashAccount::KIND_MOBILE_MONEY, 'provider' => $provider, 'is_active' => true, 'branch_id' => $branchId],
            ['name' => $branch !== null && ! $branch->is_headquarters ? "{$name} — {$branch->name}" : $name],
        );
    }

    /**
     * The branch's bank account a bank payment is ticked into — the only one
     * it has, or the one named when it has several.
     */
    public function bankFor(?int $branchId, ?string $accountUuid = null): CashAccount
    {
        $banks = CashAccount::query()->withoutGlobalScope(BranchScope::class)
            ->where('kind', CashAccount::KIND_BANK)->where('is_active', true)
            ->where('branch_id', $branchId ?? Branch::headquartersId())
            ->get();

        $bank = $accountUuid !== null ? $banks->firstWhere('uuid', $accountUuid) : ($banks->count() === 1 ? $banks->first() : null);

        if ($bank === null) {
            throw ValidationException::withMessages(['bank_account' => $banks->isEmpty()
                ? 'This branch has no bank account in the cash book — add it there first.'
                : 'Choose the bank account the payment went into.']);
        }

        return $bank;
    }

    public function createAccount(string $kind, string $name, ?string $provider, ?string $accountNumber): CashAccount
    {
        return CashAccount::query()->create([
            'kind' => $kind,
            'name' => $name,
            'provider' => $provider,
            'account_number' => $accountNumber,
            'is_active' => true,
            // The branch being worked in (a fenced user's, or the one an unfenced user is viewing), else headquarters.
            'branch_id' => TenantContext::currentBranchId() ?? Branch::headquartersId(),
        ]);
    }

    public function balance(CashAccount $account): string
    {
        return $this->balances([$account->id])[$account->id] ?? '0.00';
    }

    /**
     * @param  list<int>|null  $accountIds  null = every account
     * @return array<int, string> account id => balance
     */
    public function balances(?array $accountIds = null): array
    {
        return CashMovement::query()
            ->when($accountIds !== null, fn ($query) => $query->whereIn('cash_account_id', $accountIds))
            ->selectRaw("cash_account_id, sum(case when direction = 'in' then amount else -amount end) as balance")
            ->groupBy('cash_account_id')
            ->pluck('balance', 'cash_account_id')
            ->map(fn ($balance): string => number_format((float) $balance, 2, '.', ''))
            ->all();
    }

    /**
     * Money into or out of one account. Tills refuse a closed day.
     *
     * @param  array{document_number?: ?string, source?: ?Model, counterpart?: ?CashAccount, reference?: ?string, note?: ?string, reversal_of_id?: ?int, business_date?: string}  $details
     */
    public function record(CashAccount $account, string $direction, string $amount, string $kind, User $actor, array $details = []): CashMovement
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages(['account' => "{$account->name} is no longer in use."]);
        }

        $businessDate = $details['business_date'] ?? $this->today();
        $this->assertDayOpen($account, $businessDate);

        $source = $details['source'] ?? null;

        return CashMovement::query()->create([
            'cash_account_id' => $account->id,
            'direction' => $direction,
            'amount' => $amount,
            'kind' => $kind,
            'document_number' => $details['document_number'] ?? null,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'counterpart_account_id' => ($details['counterpart'] ?? null)?->id,
            'reference' => $details['reference'] ?? null,
            'business_date' => $businessDate,
            'recorded_by' => $actor->id,
            'reversal_of_id' => $details['reversal_of_id'] ?? null,
            'note' => $details['note'] ?? null,
        ]);
    }

    /**
     * Money moved at once between two company accounts (TR-…) — the safe, a
     * wallet, one's own till. The source must actually hold the money, and
     * a till is emptied only by its holder (owner, 2026-10-29: nobody moves
     * money out of someone else's drawer). Money for another person's till
     * or for the bank waits for someone else instead (CashTransferService).
     */
    public function transfer(CashAccount $from, CashAccount $to, string $amount, User $actor, ?string $reference, ?string $note): string
    {
        $this->assertCanMove($from, $to, $actor);

        if ($to->kind === CashAccount::KIND_BANK || ($to->isTill() && $to->holder_user_id !== $actor->id)) {
            throw ValidationException::withMessages(['to_account' => 'Money for the bank or for someone else\'s till waits for them — record it as a deposit or a handover.']);
        }

        return DB::transaction(function () use ($from, $to, $amount, $actor, $reference, $note): string {
            $this->assertHolds($from, $amount);

            $number = $this->documentNumbers->next(DocumentNumberService::TRANSFER);

            $this->record($from, 'out', $amount, CashMovement::KIND_TRANSFER, $actor, ['document_number' => $number, 'counterpart' => $to, 'reference' => $reference, 'note' => $note]);
            $this->record($to, 'in', $amount, CashMovement::KIND_TRANSFER, $actor, ['document_number' => $number, 'counterpart' => $from, 'reference' => $reference, 'note' => $note]);

            return $number;
        });
    }

    /**
     * The checks every move between two accounts of the branch shares: two
     * different accounts of one branch, and a till only by its holder.
     */
    public function assertCanMove(CashAccount $from, CashAccount $to, User $actor): void
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_account' => 'Choose a different account to move the money to.']);
        }

        // Branches v2: there is no joint account — money crosses branches only
        // as a branch transfer the receiving branch confirms.
        if ($from->branch_id !== $to->branch_id) {
            throw ValidationException::withMessages(['to_account' => 'That account belongs to another branch — send a branch transfer instead.']);
        }

        if ($from->isTill() && $from->holder_user_id !== $actor->id) {
            throw ValidationException::withMessages(['from_account' => "Only {$from->holder?->name} moves money out of their till — ask them to hand it over."]);
        }
    }

    /** Call inside the transaction: locks the account, then checks it holds $amount. */
    public function assertHolds(CashAccount $account, string $amount): void
    {
        CashAccount::query()->withoutGlobalScope(BranchScope::class)->whereKey($account->id)->lockForUpdate()->first();

        if (bccomp($this->balance($account), $amount, 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => "{$account->name} only holds ".number_format((float) $this->balance($account), 0, '.', ' ').' FCFA.',
            ]);
        }
    }

    /**
     * The money a safe, bank or wallet already held when cash controls
     * started in its branch (owner, 2026-10-29): never on a till — a till
     * fills only through handovers — and never once the branch's cash book
     * has moved money, when it could only plug a gap.
     */
    public function openingBalance(CashAccount $account, string $amount, User $actor): CashMovement
    {
        if ($account->isTill()) {
            throw ValidationException::withMessages(['amount' => 'A till starts empty — money comes into it through handovers and transfers.']);
        }

        if ($account->movements()->exists()) {
            throw ValidationException::withMessages(['amount' => 'An opening balance can only be set before the account has any movements.']);
        }

        $started = CashMovement::query()->withoutGlobalScope(BranchScope::class)
            ->where('kind', '!=', CashMovement::KIND_OPENING_BALANCE)
            ->whereIn('cash_account_id', CashAccount::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $account->branch_id)->select('id'))
            ->exists();

        if ($started) {
            throw ValidationException::withMessages(['amount' => 'Cash controls have started in this branch — opening balances are closed. Bring money into this account with a transfer or a deposit.']);
        }

        return $this->record($account, 'in', $amount, CashMovement::KIND_OPENING_BALANCE, $actor, ['note' => 'Opening balance']);
    }

    /**
     * Ends a till's day: counts the cash against the book. Any difference
     * is booked as a close adjustment so the book matches the drawer, and a
     * shortfall is the till holder's shortage (ShortageService) — never
     * hidden. The day closed is the earliest one still open
     * (dayToClose()): yesterday's before today's (owner, 2026-10-28).
     *
     * Closing again after a reopen adds only the new difference — the
     * first one stays, on the close and as its shortage.
     *
     * @param  array<string, int>|null  $denominations
     */
    public function closeDay(CashAccount $till, string $counted, ?array $denominations, ?string $note, User $actor): CashDayClose
    {
        $date = $this->dayToClose($till);

        // A handover not yet accepted may still come back into the till.
        if (CashTransfer::query()->withoutGlobalScope(BranchScope::class)->inTransit()->where('from_account_id', $till->id)->where('kind', CashTransfer::KIND_HANDOVER)->exists()) {
            throw ValidationException::withMessages(['counted' => 'A handover from this till is not accepted yet — it is accepted, refused or recalled before the day closes.']);
        }

        return DB::transaction(function () use ($till, $date, $counted, $denominations, $note, $actor): CashDayClose {
            $close = CashDayClose::query()->where('cash_account_id', $till->id)->whereDate('business_date', $date)->lockForUpdate()->first();

            if ($close?->isClosed()) {
                throw ValidationException::withMessages(['counted' => 'This day is already closed for this till.']);
            }

            $book = $this->balanceOn($till, $date);
            $variance = bcsub($counted, $book, 2);
            $inAbsence = $till->holder_user_id !== null && $till->holder_user_id !== $actor->id;

            if (bccomp($variance, '0', 2) !== 0) {
                $adjustment = $this->record($till, bccomp($variance, '0', 2) > 0 ? 'in' : 'out', ltrim($variance, '-'), CashMovement::KIND_CLOSE_ADJUSTMENT, $actor, [
                    'business_date' => $date,
                    'note' => 'Day close count difference'.($inAbsence ? " (counted by {$actor->name})" : '').($note ? ": {$note}" : ''),
                ]);

                if (bccomp($variance, '0', 2) < 0 && $till->holder_user_id !== null) {
                    app(ShortageService::class)->open($till->holder_user_id, $till->branch_id, ltrim($variance, '-'), CashShortage::KIND_TILL_CLOSE, $adjustment, $actor,
                        "Day close of {$date}".($inAbsence ? ", counted by {$actor->name} in their absence" : ''));
                }
            }

            $earlier = $close !== null ? (string) $close->variance : '0';
            $attributes = [
                // What the book held before any count's adjustment, so that
                // counted − expected is the day's whole difference.
                'expected' => bcsub($book, $earlier, 2),
                'counted' => $counted,
                'denominations' => $denominations,
                'variance' => bcadd($earlier, $variance, 2),
                'note' => $note,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ];

            if ($close) {
                $close->update($attributes);

                return $close;
            }

            return CashDayClose::query()->create(['cash_account_id' => $till->id, 'business_date' => $date, ...$attributes]);
        });
    }

    public function reopenDay(CashDayClose $close, string $reason, User $actor): CashDayClose
    {
        $close->update(['closed_at' => null, 'reopened_by' => $actor->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);

        return $close;
    }

    public function isDayClosed(CashAccount $account, ?string $date = null): bool
    {
        return CashDayClose::query()
            ->where('cash_account_id', $account->id)
            ->whereDate('business_date', $date ?? $this->today())
            ->whereNotNull('closed_at')
            ->exists();
    }

    /**
     * The till's day to count next: the last earlier day it moved money on,
     * while that day is not closed — else today. Days it did not move money
     * (a weekend, the cashier away) need no close.
     */
    public function dayToClose(CashAccount $till): string
    {
        $today = $this->today();
        $last = CashMovement::query()->withoutGlobalScope(BranchScope::class)
            ->where('cash_account_id', $till->id)->where('business_date', '<', $today)->max('business_date');

        if ($last === null) {
            return $today;
        }

        $last = Carbon::parse($last)->toDateString();

        return $this->isDayClosed($till, $last) ? $today : $last;
    }

    /** Whether money can go into or out of the till today: today is open and the last day is closed. */
    public function takesMovementsToday(CashAccount $till): bool
    {
        return ! $this->isDayClosed($till) && $this->dayToClose($till) === $this->today();
    }

    /** The account's balance at the end of $date. */
    public function balanceOn(CashAccount $account, string $date): string
    {
        $balance = CashMovement::query()->withoutGlobalScope(BranchScope::class)
            ->where('cash_account_id', $account->id)->where('business_date', '<=', $date)
            ->selectRaw("coalesce(sum(case when direction = 'in' then amount else -amount end), 0) as balance")
            ->value('balance');

        return number_format((float) $balance, 2, '.', '');
    }

    /**
     * A month's statement balance (bank, wallet) or count (safe) against the
     * book at that month's end (owner, 2026-10-28, wave 3). A difference is
     * not booked away: it stays on the alerts until what the book is missing
     * is recorded and the statement entered again (which replaces it).
     */
    public function recordStatementCount(CashAccount $account, string $period, string $statementBalance, ?UploadedFile $photo, ?string $note, User $actor): CashStatementCount
    {
        if ($account->isTill()) {
            throw ValidationException::withMessages(['account' => 'A till is counted at each day close, not monthly.']);
        }

        if ($period >= BusinessTimezone::now()->format('Y-m')) {
            throw ValidationException::withMessages(['period' => 'Count a month that has ended.']);
        }

        $book = $this->balanceOn($account, Carbon::createFromFormat('!Y-m', $period)->endOfMonth()->toDateString());

        return CashStatementCount::query()->updateOrCreate(
            ['cash_account_id' => $account->id, 'period' => $period],
            [
                'branch_id' => $account->branch_id,
                'book_balance' => $book,
                'statement_balance' => $statementBalance,
                'difference' => bcsub($statementBalance, $book, 2),
                'photo_path' => $photo?->store('statements', (string) config('filesystems.default', 'local')),
                'note' => $note,
                'counted_by' => $actor->id,
            ],
        );
    }

    /**
     * Why $user cannot leave yet (owner, 2026-10-29): money in their till,
     * collections not handed over, a handover not yet received, money in
     * transit they sent or must accept. Their open shortages do not block —
     * they stay on the record, owed.
     *
     * @return list<string>
     */
    public function moneyHeldBy(User $user): array
    {
        $reasons = [];
        $till = $this->activeTillOf($user);

        if ($till !== null && bccomp($this->balance($till), '0', 2) !== 0) {
            $reasons[] = 'their till holds '.number_format((float) $this->balance($till), 0, '.', ' ').' FCFA — they hand it over first';
        }

        $unhanded = app(RemittanceService::class)->outstandingQuery($user)->count();

        if ($unhanded > 0) {
            $reasons[] = "{$unhanded} payment(s) they collected are not handed over";
        }

        if (Remittance::query()->withoutGlobalScope(BranchScope::class)->where('collector_user_id', $user->id)->where('status', 'submitted')->exists()) {
            $reasons[] = 'a handover of theirs is waiting to be received';
        }

        if (CashTransfer::query()->withoutGlobalScope(BranchScope::class)->inTransit()->where(fn ($query) => $query->where('sent_by', $user->id)->orWhere('to_user_id', $user->id))->exists()) {
            $reasons[] = 'money they sent or must accept is still in transit';
        }

        return $reasons;
    }

    private function assertDayOpen(CashAccount $account, string $date): void
    {
        if (! $account->isTill()) {
            return;
        }

        if ($this->isDayClosed($account, $date)) {
            throw ValidationException::withMessages([
                'account' => "{$account->name} is closed for ".($date === $this->today() ? 'today' : $date).'. A supervisor must reopen the day first.',
            ]);
        }

        if ($date === $this->today() && ($pending = $this->dayToClose($account)) !== $date) {
            throw ValidationException::withMessages([
                'account' => "{$account->name} still has {$pending} open — count the drawer and close that day first.",
            ]);
        }
    }

    /**
     * Total of a counted denomination list — the figure a count must equal.
     *
     * @param  array<string|int, int|string|null>|null  $denominations
     */
    public static function denominationTotal(?array $denominations): string
    {
        $total = '0.00';

        foreach ($denominations ?? [] as $value => $count) {
            if (in_array((int) $value, self::DENOMINATIONS, true) && (int) $count > 0) {
                $total = bcadd($total, (string) ((int) $value * (int) $count), 2);
            }
        }

        return $total;
    }

    /**
     * A submitted note-and-coin count → only the known denominations with a
     * positive count, keyed by value; null when nothing was counted.
     *
     * @param  array<string|int, mixed>|null  $denominations
     * @return array<string, int>|null
     */
    public static function normalizeDenominations(?array $denominations): ?array
    {
        $filled = [];

        foreach ($denominations ?? [] as $value => $count) {
            if (in_array((int) $value, self::DENOMINATIONS, true) && (int) $count > 0) {
                $filled[(string) $value] = (int) $count;
            }
        }

        return $filled === [] ? null : $filled;
    }

    /**
     * @return Collection<int, CashAccount>
     */
    public function activeAccounts(): Collection
    {
        return CashAccount::query()->with('holder')->where('is_active', true)->orderBy('kind')->orderBy('name')->get();
    }
}
