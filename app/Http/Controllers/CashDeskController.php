<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\CashAccount;
use App\Models\CashClose;
use App\Models\CashDeposit;
use App\Models\CashHandover;
use App\Models\CashLedgerEntry;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashDeskService;
use App\Services\CashLedgerService;
use App\Services\PeriodLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Resources → Cash desk (web). Everyone who handles money sees their own
 * till and can hand cash over, confirm cash handed to them, count and
 * deposit. With `cash.view` the page also shows every account, what is
 * waiting for a decision and the red flags; `cash.manage` decides on other
 * people's counts and deposits and sets up accounts; the owner locks
 * months and checks the ledger's seal.
 */
class CashDeskController extends Controller
{
    public function __construct(
        private readonly CashDeskService $desk,
        private readonly CashLedgerService $ledger,
        private readonly PeriodLockService $periods,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $user = $request->user();
        $canView = $user->can('viewDesk', CashAccount::class);

        $selected = $request->query('account');
        $ledgerAccount = $selected !== null && $canView
            ? CashAccount::query()->where('uuid', $selected)->first()
            : $this->ledger->tillFor($user);

        return Inertia::render('Cash/Index', [
            'me' => $this->desk->summaryFor($user),
            'can' => [
                'view_desk' => $canView,
                'manage' => $user->can('manage', CashAccount::class),
                'approve_large' => $user->can('approveLarge', CashAccount::class),
                'lock_periods' => $user->can('lockPeriods', CashAccount::class),
            ],
            'targets' => $this->desk->handoverTargets($user)->map(fn (CashAccount $account): array => [
                'uuid' => $account->uuid, 'type' => $account->type, 'name' => $account->name, 'holder' => $account->holder?->name,
            ])->values()->all(),
            'deposit_accounts' => CashAccount::query()->where('is_active', true)->whereNotIn('type', CashAccount::CASH_TYPES)->orderBy('name')->get(['uuid', 'type', 'name'])->toArray(),
            'desk' => $canView ? $this->deskOverview($user) : null,
            'ledger' => $ledgerAccount === null ? null : [
                'account' => $this->desk->accountRow($ledgerAccount->loadMissing('holder')),
                'entries' => $this->ledgerPage($ledgerAccount),
            ],
            'users' => $user->can('manage', CashAccount::class)
                // tenant_users lives in the tenant schema, users centrally:
                // two queries, not a cross-schema subquery.
                ? User::query()->whereIn('id', TenantUser::query()->pluck('user_id'))->orderBy('name')->get(['uuid', 'name'])->toArray()
                : [],
            'denominations' => CashDeskService::DENOMINATIONS,
        ]);
    }

    public function startHandover(Request $request): RedirectResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $validated = $request->validate([
            'to_account_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $handover = $this->desk->startHandover(
            $request->user(),
            CashAccount::query()->where('uuid', $validated['to_account_uuid'])->firstOrFail(),
            (string) $validated['amount'],
            null,
            $validated['note'] ?? null,
        );

        return back()->with('success', "Handover started. Show the receiver this code: {$handover->code}");
    }

    public function confirmHandover(Request $request, CashHandover $handover): RedirectResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'received_amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ]);

        $handover = $this->desk->confirmHandover($handover, $request->user(), $validated['code'], (string) $validated['received_amount']);

        return back()->with('success', $handover->status === CashHandover::STATUS_SHORT ? 'Received — the shortfall stays with the giver and the manager was told.' : 'Cash received.');
    }

    public function cancelHandover(Request $request, CashHandover $handover): RedirectResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $this->desk->cancelHandover($handover, $request->user());

        return back()->with('success', 'Handover cancelled.');
    }

    public function submitClose(Request $request): RedirectResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $validated = $request->validate([
            'denominations' => ['present', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
            'account_uuid' => ['nullable', 'uuid'],
        ]);

        $account = isset($validated['account_uuid']) ? CashAccount::query()->where('uuid', $validated['account_uuid'])->firstOrFail() : null;
        $close = $this->desk->submitClose($request->user(), $validated['denominations'], $validated['note'] ?? null, $account);

        return back()->with('success', match ($close->status) {
            CashClose::STATUS_BALANCED => 'Counted and balanced.',
            CashClose::STATUS_APPROVED => 'Counted — a small difference was settled automatically.',
            default => 'Counted — the difference waits for a manager.',
        });
    }

    public function decideClose(Request $request, CashClose $close): RedirectResponse
    {
        $this->authorize($this->desk->needsOwner($close) ? 'approveLarge' : 'manage', CashAccount::class);

        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'note' => [Rule::requiredIf(! $request->boolean('approve')), 'nullable', 'string', 'max:500'],
        ]);

        $this->desk->decideClose($close->load('account'), $request->user(), (bool) $validated['approve'], $validated['note'] ?? null);

        return back()->with('success', $validated['approve'] ? 'Difference approved and posted.' : 'Count sent back — it must be counted again.');
    }

    public function deposit(Request $request): RedirectResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $validated = $request->validate([
            'from_account_uuid' => ['nullable', 'uuid'],
            'to_account_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'reference' => ['required', 'string', 'max:80'],
            'slip' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:6144'],
        ]);

        $user = $request->user();
        $from = isset($validated['from_account_uuid'])
            ? CashAccount::query()->where('uuid', $validated['from_account_uuid'])->firstOrFail()
            : $this->ledger->tillFor($user);

        $this->desk->deposit($user, $from, CashAccount::query()->where('uuid', $validated['to_account_uuid'])->firstOrFail(), (string) $validated['amount'], $validated['reference'], $request->file('slip'));

        return back()->with('success', 'Deposit recorded — a second person confirms it against the bank statement.');
    }

    public function decideDeposit(Request $request, CashDeposit $deposit): RedirectResponse
    {
        $this->authorize('manage', CashAccount::class);

        $validated = $request->validate([
            'confirm' => ['required', 'boolean'],
            'note' => [Rule::requiredIf(! $request->boolean('confirm')), 'nullable', 'string', 'max:500'],
        ]);

        $this->desk->decideDeposit($deposit, $request->user(), (bool) $validated['confirm'], $validated['note'] ?? null);

        return back()->with('success', $validated['confirm'] ? 'Deposit confirmed.' : 'Deposit rejected and reversed.');
    }

    public function depositSlip(CashDeposit $deposit): StreamedResponse
    {
        $this->authorize('viewDesk', CashAccount::class);

        abort_if($deposit->slip_path === null || ! Storage::disk('local')->exists($deposit->slip_path), 404);

        return Storage::disk('local')->response($deposit->slip_path);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $this->authorize('manage', CashAccount::class);

        $validated = $request->validate([
            'type' => ['required', Rule::in([CashAccount::TYPE_SAFE, CashAccount::TYPE_PETTY_CASH, CashAccount::TYPE_BANK, CashAccount::TYPE_MTN_MOMO, CashAccount::TYPE_ORANGE_MONEY])],
            'name' => ['required', 'string', 'max:120'],
            'holder_uuid' => [Rule::requiredIf(in_array($request->input('type'), [CashAccount::TYPE_SAFE, CashAccount::TYPE_PETTY_CASH], true)), 'nullable', 'uuid'],
            'max_hold' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        CashAccount::query()->create([
            'type' => $validated['type'],
            'name' => $validated['name'],
            'holder_user_id' => isset($validated['holder_uuid']) ? User::query()->where('uuid', $validated['holder_uuid'])->value('id') : null,
            'max_hold' => $validated['max_hold'] ?? null,
        ]);

        return back()->with('success', 'Account added.');
    }

    public function updateAccount(Request $request, CashAccount $account): RedirectResponse
    {
        $this->authorize('manage', CashAccount::class);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'max_hold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'is_active' => ['sometimes', 'boolean'],
            'holder_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (array_key_exists('holder_uuid', $validated)) {
            // Cash can't change hands by editing a field: empty the account
            // with a handover first.
            if (bccomp((string) $account->balance, '0', 2) !== 0 || $account->type === CashAccount::TYPE_TILL) {
                return back()->withErrors(['holder_uuid' => 'Hand the cash over first — the holder can only change when the account is empty.']);
            }

            $validated['holder_user_id'] = $validated['holder_uuid'] ? User::query()->where('uuid', $validated['holder_uuid'])->value('id') : null;
            unset($validated['holder_uuid']);
        }

        if (($validated['is_active'] ?? true) === false && bccomp((string) $account->balance, '0', 2) !== 0) {
            return back()->withErrors(['is_active' => 'An account holding money cannot be switched off.']);
        }

        $account->forceFill($validated)->save();

        return back()->with('success', 'Account updated.');
    }

    public function lockPeriod(Request $request): RedirectResponse
    {
        $this->authorize('lockPeriods', CashAccount::class);

        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->periods->lock($validated['period'], $request->user(), $validated['note'] ?? null);

        return back()->with('success', "{$validated['period']} is closed.");
    }

    public function verifyLedger(): RedirectResponse
    {
        $this->authorize('lockPeriods', CashAccount::class);

        $result = $this->ledger->verify();

        if ($result['broken_at'] === null && $result['balance_mismatches'] === []) {
            return back()->with('success', "Ledger checked: {$result['entries']} entries, seal intact, every balance matches.");
        }

        return back()->with('error', 'Ledger check FAILED: '.($result['broken_at'] !== null ? "the chain breaks at entry #{$result['broken_at']}. " : '').count($result['balance_mismatches']).' balance(s) differ.');
    }

    /**
     * The owner / manager overview: every account, what waits for a
     * decision, and the red flags.
     *
     * @return array<string, mixed>
     */
    private function deskOverview(User $viewer): array
    {
        $accounts = CashAccount::query()->with('holder')->where('is_active', true)->orderByRaw("case type when 'safe' then 0 when 'petty_cash' then 1 when 'till' then 2 else 3 end")->orderBy('name')->get();

        // Last close per account, one query.
        $lastCloses = CashClose::query()
            ->whereIn('id', CashClose::query()->selectRaw('max(id)')->groupBy('account_id'))
            ->get(['account_id', 'business_date', 'status', 'variance'])
            ->keyBy('account_id');

        // Shortages per account over 30 days, one query.
        $shortages = CashClose::query()
            ->where('variance', '<', 0)
            ->whereIn('status', [CashClose::STATUS_APPROVED, CashClose::STATUS_PENDING])
            ->where('business_date', '>=', Carbon::today()->subDays(30))
            ->groupBy('account_id')
            ->selectRaw('account_id, count(*) as shortages, sum(variance) as total')
            ->get()
            ->keyBy('account_id');

        $today = Carbon::now('Africa/Douala')->startOfDay();
        $flags = [];

        $rows = $accounts->map(function (CashAccount $account) use ($lastCloses, $shortages, $today, &$flags): array {
            $last = $lastCloses->get($account->id);
            $daysSinceClose = $last === null ? null : (int) Carbon::parse($last->business_date)->diffInDays($today);
            $short = $shortages->get($account->id);

            if ($account->isCash() && bccomp((string) $account->balance, '0', 2) > 0 && ($daysSinceClose === null || $daysSinceClose > 1)) {
                $flags[] = ['level' => 'warning', 'text' => "{$account->name} holds ".number_format((float) $account->balance, 0, '.', ',').' FCFA and '.($daysSinceClose === null ? 'has never been counted.' : "was last counted {$daysSinceClose} days ago.")];
            }

            if ($account->max_hold !== null && bccomp((string) $account->balance, (string) $account->max_hold, 2) > 0) {
                $flags[] = ['level' => 'danger', 'text' => "{$account->name} holds more than its limit of ".number_format((float) $account->max_hold, 0, '.', ',').' FCFA.'];
            }

            if ($short !== null && (int) $short->shortages >= 2) {
                $flags[] = ['level' => 'danger', 'text' => "{$account->name}: {$short->shortages} shortages in 30 days (".number_format(abs((float) $short->total), 0, '.', ',').' FCFA).'];
            }

            return [
                ...$this->desk->accountRow($account),
                'last_close' => $last === null ? null : ['date' => Carbon::parse($last->business_date)->toDateString(), 'status' => $last->status, 'variance' => (string) $last->variance],
                'days_since_close' => $daysSinceClose,
                'shortages_30d' => (int) ($short->shortages ?? 0),
            ];
        })->values()->all();

        $staleHandovers = CashHandover::query()->with(['fromAccount', 'toAccount', 'initiator'])
            ->where('status', CashHandover::STATUS_PENDING)->where('initiated_at', '<', Carbon::now()->subHours(2))->get();

        foreach ($staleHandovers as $handover) {
            $flags[] = ['level' => 'warning', 'text' => "Handover {$handover->fromAccount?->name} → {$handover->toAccount?->name} (".number_format((float) $handover->amount, 0, '.', ',').' FCFA) not confirmed after 2 hours.'];
        }

        $pendingDeposits = CashDeposit::query()->with(['fromAccount', 'toAccount', 'depositor'])->where('status', CashDeposit::STATUS_PENDING)->oldest()->get();

        foreach ($pendingDeposits->where('created_at', '<', Carbon::now()->subHours(48)) as $deposit) {
            $flags[] = ['level' => 'warning', 'text' => 'Deposit of '.number_format((float) $deposit->amount, 0, '.', ',')." FCFA by {$deposit->depositor?->name} not confirmed after 48 hours."];
        }

        $totals = $accounts->groupBy('type')->map(fn ($group): string => bcadd((string) $group->sum('balance'), '0', 2));

        return [
            'accounts' => $rows,
            'totals' => [
                'cash_outside_bank' => bcadd((string) $accounts->filter->isCash()->sum('balance'), '0', 2),
                'by_type' => $totals,
            ],
            'flags' => $flags,
            'pending_closes' => CashClose::query()->with(['account', 'closer'])->where('status', CashClose::STATUS_PENDING)->oldest('business_date')->get()
                ->map(fn (CashClose $close): array => [...$this->desk->closeRow($close), 'is_mine' => $close->closed_by === $viewer->id])->values()->all(),
            'pending_deposits' => $pendingDeposits->map(fn (CashDeposit $deposit): array => [
                'uuid' => $deposit->uuid,
                'from' => $deposit->fromAccount?->name,
                'to' => $deposit->toAccount?->name,
                'amount' => (string) $deposit->amount,
                'reference' => $deposit->reference,
                'deposited_by' => $deposit->depositor?->name,
                'created_at' => $deposit->created_at?->toIso8601String(),
                'has_slip' => $deposit->slip_path !== null,
                'is_mine' => $deposit->deposited_by === $viewer->id,
            ])->values()->all(),
            'recent_handovers' => CashHandover::query()->with(['fromAccount', 'toAccount', 'initiator'])->latest('initiated_at')->limit(20)->get()
                ->map(fn (CashHandover $handover): array => $this->desk->handoverRow($handover))->values()->all(),
            'locked_periods' => AccountingPeriod::query()->orderByDesc('period')->limit(12)->pluck('period')->all(),
        ];
    }

    /**
     * One account's ledger, newest first, each row with the balance after
     * it (a window sum, computed by the database in one pass).
     *
     * @return list<array<string, mixed>>
     */
    private function ledgerPage(CashAccount $account): array
    {
        $rows = CashLedgerEntry::query()
            ->fromSub(
                CashLedgerEntry::query()
                    ->where('account_id', $account->id)
                    ->selectRaw("cash_ledger.*, sum(case when direction = 'in' then amount else -amount end) over (order by id) as balance_after"),
                'cash_ledger',
            )
            ->with('recorder')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return $rows->map(fn (CashLedgerEntry $entry): array => [
            'uuid' => $entry->uuid,
            'type' => $entry->type,
            'label' => CashLedgerEntry::TYPE_LABELS[$entry->type] ?? $entry->type,
            'direction' => $entry->direction,
            'amount' => (string) $entry->amount,
            'balance_after' => bcadd((string) $entry->getAttribute('balance_after'), '0', 2),
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
            'recorded_by' => $entry->recorder?->name,
            'note' => $entry->note,
            'reversed' => $entry->reverses_id !== null,
        ])->values()->all();
    }
}
