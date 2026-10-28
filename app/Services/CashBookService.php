<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\CashAccount;
use App\Models\CashDayClose;
use App\Models\CashMovement;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Support\BranchAttribution;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
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
            && ! BranchTransfer::query()->where('from_account_id', $till->id)->where('status', BranchTransfer::STATUS_SENT)->exists()) {
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
     * @param  array{document_number?: ?string, source?: ?Model, counterpart?: ?CashAccount, reference?: ?string, note?: ?string, reversal_of_id?: ?int}  $details
     */
    public function record(CashAccount $account, string $direction, string $amount, string $kind, User $actor, array $details = []): CashMovement
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages(['account' => "{$account->name} is no longer in use."]);
        }

        $this->assertDayOpen($account);

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
            'business_date' => $this->today(),
            'recorded_by' => $actor->id,
            'reversal_of_id' => $details['reversal_of_id'] ?? null,
            'note' => $details['note'] ?? null,
        ]);
    }

    /**
     * Money moved between two company accounts — a bank deposit (DP-…)
     * when it lands in a bank account, otherwise a transfer (TR-…). The
     * source must actually hold the money.
     */
    public function transfer(CashAccount $from, CashAccount $to, string $amount, User $actor, ?string $reference, ?string $note): string
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_account' => 'Choose a different account to move the money to.']);
        }

        // Branches v2: there is no joint account — money crosses branches only
        // as a branch transfer the receiving branch confirms.
        if ($from->branch_id !== $to->branch_id) {
            throw ValidationException::withMessages(['to_account' => 'That account belongs to another branch — send a branch transfer instead.']);
        }

        if ($to->kind === CashAccount::KIND_BANK && ($reference === null || trim($reference) === '')) {
            throw ValidationException::withMessages(['reference' => 'Enter the bank deposit slip number.']);
        }

        return DB::transaction(function () use ($from, $to, $amount, $actor, $reference, $note): string {
            CashAccount::query()->whereKey($from->id)->lockForUpdate()->first();

            if (bccomp($this->balance($from), $amount, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => "{$from->name} only holds ".number_format((float) $this->balance($from), 0, '.', ' ').' FCFA.',
                ]);
            }

            $isDeposit = $to->kind === CashAccount::KIND_BANK;
            $number = $this->documentNumbers->next($isDeposit ? DocumentNumberService::DEPOSIT : DocumentNumberService::TRANSFER);
            $kind = $isDeposit ? CashMovement::KIND_DEPOSIT : CashMovement::KIND_TRANSFER;

            $this->record($from, 'out', $amount, $kind, $actor, ['document_number' => $number, 'counterpart' => $to, 'reference' => $reference, 'note' => $note]);
            $this->record($to, 'in', $amount, $kind, $actor, ['document_number' => $number, 'counterpart' => $from, 'reference' => $reference, 'note' => $note]);

            return $number;
        });
    }

    /** The money an account already held when cash controls started. Only on an account with no movements. */
    public function openingBalance(CashAccount $account, string $amount, User $actor): CashMovement
    {
        if ($account->movements()->exists()) {
            throw ValidationException::withMessages(['amount' => 'An opening balance can only be set before the account has any movements.']);
        }

        return $this->record($account, 'in', $amount, CashMovement::KIND_OPENING_BALANCE, $actor, ['note' => 'Opening balance']);
    }

    /**
     * Ends a till's day: counts the cash against the book. Any difference
     * is booked as a close adjustment so the book matches the drawer, and
     * stays visible on the close (and in the alerts) — it is never hidden.
     *
     * @param  array<string, int>|null  $denominations
     */
    public function closeDay(CashAccount $till, string $counted, ?array $denominations, ?string $note, User $actor): CashDayClose
    {
        return DB::transaction(function () use ($till, $counted, $denominations, $note, $actor): CashDayClose {
            $today = $this->today();

            $close = CashDayClose::query()->where('cash_account_id', $till->id)->whereDate('business_date', $today)->lockForUpdate()->first();

            if ($close?->isClosed()) {
                throw ValidationException::withMessages(['counted' => 'Today is already closed for this till.']);
            }

            $expected = $this->balance($till);
            $variance = bcsub($counted, $expected, 2);

            if (bccomp($variance, '0', 2) !== 0) {
                $this->record($till, bccomp($variance, '0', 2) > 0 ? 'in' : 'out', ltrim($variance, '-'), CashMovement::KIND_CLOSE_ADJUSTMENT, $actor, [
                    'note' => 'Day close count difference'.($note ? ": {$note}" : ''),
                ]);
            }

            $attributes = [
                'expected' => $expected,
                'counted' => $counted,
                'denominations' => $denominations,
                'variance' => $variance,
                'note' => $note,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ];

            if ($close) {
                $close->update($attributes);

                return $close;
            }

            return CashDayClose::query()->create(['cash_account_id' => $till->id, 'business_date' => $today, ...$attributes]);
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

    private function assertDayOpen(CashAccount $account): void
    {
        if ($account->isTill() && $this->isDayClosed($account)) {
            throw ValidationException::withMessages([
                'account' => "{$account->name} is closed for today. A supervisor must reopen the day first.",
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
