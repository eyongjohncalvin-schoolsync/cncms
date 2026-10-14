<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashLedgerEntry;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only way money moves on the cash desk. Each posting:
 *
 * 1. takes a transaction-scoped advisory lock, so ledger rows are written
 *    one at a time and the hash chain is a single unbroken line;
 * 2. locks the account row and updates its cached balance in the same
 *    transaction (a cash account can never go below zero);
 * 3. writes an append-only row whose hash covers the previous row's hash
 *    and every money fact of this one.
 *
 * verify() recomputes the chain and every balance from scratch — run
 * nightly (cash:verify-ledger) so any change made behind the application's
 * back is caught.
 */
class CashLedgerService
{
    /** pg_advisory_xact_lock key for the ledger chain (per schema = per tenant). */
    private const int CHAIN_LOCK = 742_118_301;

    public function __construct(
        private readonly PeriodLockService $periods,
    ) {}

    /**
     * The person's own till, created on first use.
     */
    public function tillFor(User $user): CashAccount
    {
        return CashAccount::query()->where('type', CashAccount::TYPE_TILL)->where('holder_user_id', $user->id)->first()
            ?? CashAccount::query()->create([
                'type' => CashAccount::TYPE_TILL,
                'name' => $user->name,
                'holder_user_id' => $user->id,
                'branch_id' => TenantContext::currentBranchId(),
            ]);
    }

    /**
     * The company account money paid by a method lands in: a person's till
     * for cash, the MTN / Orange account for mobile money, the bank for
     * bank transfers (created on first use).
     */
    public function accountForMethod(string $method, User $holder): CashAccount
    {
        if ($method === 'cash') {
            return $this->tillFor($holder);
        }

        $names = ['mtn_momo' => 'MTN MoMo', 'orange_money' => 'Orange Money', 'bank' => 'Bank'];

        return CashAccount::query()->where('type', $method)->where('is_active', true)->orderBy('id')->first()
            ?? CashAccount::query()->create(['type' => $method, 'name' => $names[$method] ?? ucfirst($method)]);
    }

    /**
     * Posts one movement. $source (type + id) makes automatic postings
     * idempotent: the same source can't post the same movement twice.
     *
     * @param  array{type: string, id: int}|null  $source
     *
     * @throws ValidationException when money would leave an account that doesn't hold it
     */
    public function post(
        CashAccount $account,
        string $direction,
        string $amount,
        string $type,
        ?array $source = null,
        ?Carbon $occurredAt = null,
        ?User $by = null,
        ?string $note = null,
        ?int $reversesId = null,
        bool $allowNegative = false,
    ): CashLedgerEntry {
        return DB::transaction(function () use ($account, $direction, $amount, $type, $source, $occurredAt, $by, $note, $reversesId, $allowNegative): CashLedgerEntry {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::CHAIN_LOCK]);

            // Idempotent automatic posting: a source that already has a live
            // (not reversed) entry of this kind on this account isn't posted
            // again — checked under the chain lock, so two requests can't
            // both slip through.
            if ($source !== null && $reversesId === null) {
                $existing = CashLedgerEntry::query()
                    ->where('source_type', $source['type'])->where('source_id', $source['id'])
                    ->where('type', $type)->where('account_id', $account->id)
                    ->whereNull('reverses_id')
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cash_ledger as r')->whereColumn('r.reverses_id', 'cash_ledger.id'))
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $locked = CashAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $amount = bcadd($amount, '0', 2);
            $newBalance = $direction === CashLedgerEntry::IN ? bcadd((string) $locked->balance, $amount, 2) : bcsub((string) $locked->balance, $amount, 2);

            if (! $allowNegative && $locked->isCash() && bccomp($newBalance, '0', 2) < 0) {
                throw ValidationException::withMessages(['amount' => ["{$locked->name} only holds ".number_format((float) $locked->balance, 0, '.', ',').' FCFA.']]);
            }

            $occurredAt ??= Carbon::now();
            $previous = CashLedgerEntry::query()->orderByDesc('id')->value('hash');

            $entry = new CashLedgerEntry([
                'uuid' => (string) Str::uuid7(),
                'account_id' => $locked->id,
                'direction' => $direction,
                'amount' => $amount,
                'type' => $type,
                'source_type' => $source['type'] ?? null,
                'source_id' => $source['id'] ?? null,
                'occurred_at' => $occurredAt,
                'recorded_at' => Carbon::now(),
                'recorded_by' => $by?->id,
                'reverses_id' => $reversesId,
                'period' => $this->periods->bookingPeriod($occurredAt),
                'note' => $note !== null ? Str::limit($note, 250) : null,
                'prev_hash' => $previous,
            ]);
            $entry->hash = self::hashOf($entry);
            $entry->save();

            $locked->forceFill(['balance' => $newBalance])->save();
            $account->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }

    /**
     * Undoes an entry with an opposite one (the original stays).
     */
    public function reverse(CashLedgerEntry $entry, ?User $by, string $note): CashLedgerEntry
    {
        return $this->post(
            $entry->account,
            $entry->direction === CashLedgerEntry::IN ? CashLedgerEntry::OUT : CashLedgerEntry::IN,
            (string) $entry->amount,
            CashLedgerEntry::TYPE_REVERSAL,
            $entry->source_type !== null ? ['type' => $entry->source_type, 'id' => (int) $entry->source_id] : null,
            Carbon::now(),
            $by,
            $note,
            $entry->id,
            allowNegative: true,
        );
    }

    /**
     * Reverses every not-yet-reversed entry a source posted (e.g. a
     * rejected payment, a voided expense).
     */
    public function reverseSource(string $sourceType, int $sourceId, ?User $by, string $note): void
    {
        $entries = CashLedgerEntry::query()
            ->with('account')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('reverses_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cash_ledger as r')->whereColumn('r.reverses_id', 'cash_ledger.id'))
            ->get();

        foreach ($entries as $entry) {
            $this->reverse($entry, $by, $note);
        }
    }

    /**
     * Recomputes the hash chain and every account balance from the ledger.
     *
     * @return array{entries: int, broken_at: ?int, balance_mismatches: list<array{account: string, cached: string, ledger: string}>}
     */
    public function verify(): array
    {
        $previous = null;
        $brokenAt = null;
        $count = 0;

        CashLedgerEntry::query()->orderBy('id')->chunk(1000, function ($entries) use (&$previous, &$brokenAt, &$count): bool {
            foreach ($entries as $entry) {
                $count++;

                if ($entry->prev_hash !== $previous || $entry->hash !== self::hashOf($entry)) {
                    $brokenAt = $entry->id;

                    return false;
                }

                $previous = $entry->hash;
            }

            return true;
        });

        $ledger = CashLedgerEntry::query()
            ->groupBy('account_id')
            ->selectRaw("account_id, sum(case when direction = 'in' then amount else -amount end) as total")
            ->pluck('total', 'account_id');

        $mismatches = CashAccount::query()->get(['id', 'name', 'balance'])
            ->filter(fn (CashAccount $account): bool => bccomp((string) $account->balance, (string) ($ledger[$account->id] ?? '0'), 2) !== 0)
            ->map(fn (CashAccount $account): array => [
                'account' => $account->name,
                'cached' => (string) $account->balance,
                'ledger' => bcadd((string) ($ledger[$account->id] ?? '0'), '0', 2),
            ])->values()->all();

        return ['entries' => $count, 'broken_at' => $brokenAt, 'balance_mismatches' => $mismatches];
    }

    public static function hashOf(CashLedgerEntry $entry): string
    {
        return hash('sha256', implode('|', [
            $entry->prev_hash ?? '',
            $entry->uuid,
            $entry->account_id,
            $entry->direction,
            bcadd((string) $entry->amount, '0', 2),
            $entry->type,
            $entry->source_type ?? '',
            $entry->source_id ?? '',
            Carbon::parse($entry->occurred_at)->utc()->format('Y-m-d\TH:i:s'),
            $entry->reverses_id ?? '',
        ]));
    }
}
