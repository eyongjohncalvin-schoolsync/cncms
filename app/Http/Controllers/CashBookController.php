<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashDayClose;
use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\CashStatementCount;
use App\Models\CashTransfer;
use App\Models\Remittance;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\CashBookService;
use App\Services\CashTransferService;
use App\Services\ShortageService;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cash book screens (docs/plans/cash-controls.md, wave 4): where the
 * company's money is right now, handovers waiting to be received, open
 * shortages, the cashier's own till (with day close), and moving money
 * between accounts.
 */
class CashBookController extends Controller
{
    public function __construct(
        private readonly CashBookService $cashBook,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $canViewAll = $user->can('viewAny', CashAccount::class);
        $canReceive = $this->context()->can('cash.receive');

        if (! $canViewAll && ! $canReceive) {
            abort_unless($this->context()->can('cash.remit'), 403);

            return redirect()->route('cash.hand-over');
        }

        // One load of the active accounts (the list and the transfer targets),
        // and balances only for the accounts shown — not the whole ledger.
        $activeAccounts = $this->cashBook->activeAccounts();
        $accounts = $canViewAll ? $activeAccounts : collect();
        $myTill = $canReceive ? $this->cashBook->tillFor($user) : null;
        $balances = $this->cashBook->balances($accounts->pluck('id')->push($myTill?->id)->filter()->unique()->values()->all());

        $closesToday = CashDayClose::query()->whereDate('business_date', $this->cashBook->today())->get()->keyBy('cash_account_id');

        return Inertia::render('Cash/Index', [
            'today' => $this->cashBook->today(),
            'can' => [
                'view_all' => $canViewAll,
                'receive' => $canReceive,
                'transfer' => $user->can('transfer', CashAccount::class),
                'manage_accounts' => $user->can('create', CashAccount::class),
                'reopen_day' => $this->context()->can('vouchers.approve'),
                // Branches v2: money between branches, once there is more than one.
                'branch_transfers' => $user->can('transfer', CashAccount::class) && Branch::allMemoized()->count() > 1,
            ],
            'accounts' => $accounts->map(fn (CashAccount $account): array => $this->shapeAccount($account, $balances, $closesToday))->values()->all(),
            'my_till' => $myTill ? $this->shapeAccount($myTill, $balances, $closesToday) : null,
            'transfer_targets' => $activeAccounts->map(fn (CashAccount $account): array => [
                'uuid' => $account->uuid,
                'name' => $account->name,
                'kind' => $account->kind,
            ])->values()->all(),
            'awaiting' => Remittance::query()->with(['collector', 'receivedBy'])->where('status', 'submitted')->latest('submitted_at')->get()
                ->map(fn (Remittance $remittance): array => $this->shapeRemittance($remittance))->values()->all(),
            'open_shortages' => $canViewAll
                ? Remittance::query()->with(['collector', 'receivedBy'])->where('shortage_status', 'open')->latest('received_at')->get()
                    ->map(fn (Remittance $remittance): array => $this->shapeRemittance($remittance))->values()->all()
                : [],
            'recent' => $canViewAll
                ? Remittance::query()->with(['collector', 'receivedBy'])->where('status', 'received')->latest('received_at')->limit(20)->get()
                    ->map(fn (Remittance $remittance): array => $this->shapeRemittance($remittance))->values()->all()
                : [],
            // Wave 3: money waiting for someone, and money missing that someone owes.
            'in_transit' => CashTransfer::query()->inTransit()->with(['fromAccount', 'toAccount', 'sentBy', 'toUser', 'confirmations.user'])
                ->when(! $canViewAll, fn ($query) => $query->where(fn ($mine) => $mine->where('sent_by', $user->id)->orWhere('to_user_id', $user->id)))
                ->orderBy('sent_at')->get()
                ->map(fn (CashTransfer $transfer): array => $this->shapeTransfer($transfer, $user))->values()->all(),
            'shortages' => CashShortage::query()->open()->with('owner')
                ->when(! $canViewAll, fn ($query) => $query->where('owner_user_id', $user->id))
                ->orderBy('created_at')->get()
                ->map(fn (CashShortage $shortage): array => [
                    'uuid' => $shortage->uuid,
                    'kind' => CashShortage::KIND_LABELS[$shortage->kind] ?? $shortage->kind,
                    'owner_name' => $shortage->owner?->name,
                    'amount' => (string) $shortage->amount,
                    'note' => $shortage->note,
                    'created_at' => $shortage->created_at?->toIso8601String(),
                    'can_repay' => $shortage->owner_user_id !== $user->id && $canReceive,
                    'can_write_off' => $shortage->owner_user_id !== $user->id && $this->context()->can('vouchers.approve'),
                ])->values()->all(),
            'deposit_confirmations' => CashTransferService::confirmationsNeeded(),
            'denominations' => CashBookService::DENOMINATIONS,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeTransfer(CashTransfer $transfer, User $viewer): array
    {
        $isDeposit = $transfer->isDeposit();
        $confirmedByMe = $transfer->confirmations->contains('user_id', $viewer->id);
        $checksDeposits = $isDeposit && $transfer->sent_by !== $viewer->id && $this->context()->can('cash.view');

        return [
            'uuid' => $transfer->uuid,
            'number' => $transfer->number,
            'kind' => $transfer->kind,
            'from' => $transfer->fromAccount?->name,
            'to' => $transfer->toAccount?->name,
            'to_user_name' => $transfer->toUser?->name,
            'sent_by_name' => $transfer->sentBy?->name,
            'amount' => (string) $transfer->amount,
            'slip_number' => $transfer->slip_number,
            'note' => $transfer->note,
            'sent_at' => $transfer->sent_at?->toIso8601String(),
            'confirmed_by' => $transfer->confirmations->map(fn ($confirmation): string => (string) $confirmation->user?->name)->values()->all(),
            'required_confirmations' => $transfer->required_confirmations,
            'can_accept' => ! $isDeposit && $transfer->to_user_id === $viewer->id,
            'can_recall' => ! $isDeposit && $transfer->sent_by === $viewer->id,
            'can_confirm' => $checksDeposits && ! $confirmedByMe,
            'can_refuse_deposit' => $checksDeposits,
        ];
    }

    public function show(Request $request, CashAccount $cashAccount): Response
    {
        $this->authorize('view', $cashAccount);

        $movements = $cashAccount->movements()->with(['recordedBy', 'counterpart'])->latest('id')->limit(300)->get();
        $balance = $this->cashBook->balance($cashAccount);

        // Running balance, newest first: start from today's balance and walk back.
        $running = $balance;
        $rows = $movements->map(function (CashMovement $movement) use (&$running): array {
            $row = [
                'uuid' => $movement->uuid,
                'business_date' => $movement->business_date?->toDateString(),
                'created_at' => $movement->created_at?->toIso8601String(),
                'direction' => $movement->direction,
                'amount' => (string) $movement->amount,
                'kind' => $movement->kind,
                'document_number' => $movement->document_number,
                'reference' => $movement->reference,
                'counterpart_name' => $movement->counterpart?->name,
                'recorded_by_name' => $movement->recordedBy?->name,
                'note' => $movement->note,
                'balance_after' => $running,
            ];

            $running = $movement->direction === 'in' ? bcsub($running, (string) $movement->amount, 2) : bcadd($running, (string) $movement->amount, 2);

            return $row;
        });

        return Inertia::render('Cash/Account', [
            'account' => [
                'uuid' => $cashAccount->uuid,
                'name' => $cashAccount->name,
                'kind' => $cashAccount->kind,
                'account_number' => $cashAccount->account_number,
                'balance' => $balance,
                'has_movements' => $movements->isNotEmpty(),
            ],
            'movements' => $rows->values()->all(),
            'can_set_opening_balance' => $request->user()->can('update', $cashAccount) && $movements->isEmpty() && ! $cashAccount->isTill(),
            // Wave 3: the month's statement or count against the book.
            'can_count_statement' => $request->user()->can('countStatement', $cashAccount),
            'last_month' => BusinessTimezone::now()->subMonthNoOverflow()->format('Y-m'),
            'statements' => CashStatementCount::query()->with('countedBy')->where('cash_account_id', $cashAccount->id)->latest('period')->limit(12)->get()
                ->map(fn (CashStatementCount $count): array => [
                    'period' => $count->period,
                    'book_balance' => (string) $count->book_balance,
                    'statement_balance' => (string) $count->statement_balance,
                    'difference' => (string) $count->difference,
                    'counted_by_name' => $count->countedBy?->name,
                ])->values()->all(),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $this->authorize('create', CashAccount::class);

        $validated = $request->validate([
            'kind' => ['required', Rule::in([CashAccount::KIND_SAFE, CashAccount::KIND_BANK, CashAccount::KIND_MOBILE_MONEY])],
            'name' => ['required', 'string', 'max:100'],
            'provider' => ['nullable', 'required_if:kind,mobile_money', Rule::in(['mtn', 'orange'])],
            'account_number' => ['nullable', 'string', 'max:60'],
        ]);

        // One wallet per operator per branch (branches v2 D3) — the new account
        // joins the branch being worked in (CashBookService::createAccount()).
        if ($validated['kind'] === CashAccount::KIND_MOBILE_MONEY
            && CashAccount::query()->withoutGlobalScope(BranchScope::class)
                ->where('kind', CashAccount::KIND_MOBILE_MONEY)->where('provider', $validated['provider'])->where('is_active', true)
                ->where('branch_id', TenantContext::currentBranchId() ?? Branch::headquartersId())->exists()) {
            return back()->withErrors(['provider' => 'This branch already has a wallet for this operator.']);
        }

        $this->cashBook->createAccount($validated['kind'], $validated['name'], $validated['provider'] ?? null, $validated['account_number'] ?? null);

        return back()->with('success', "{$validated['name']} added to the cash book.");
    }

    public function openingBalance(Request $request, CashAccount $cashAccount): RedirectResponse
    {
        $this->authorize('update', $cashAccount);

        $validated = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2']]);

        $this->cashBook->openingBalance($cashAccount, (string) $validated['amount'], $request->user());

        return back()->with('success', 'Opening balance recorded.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $this->authorize('transfer', CashAccount::class);

        $validated = $request->validate([
            'from_account' => ['required', 'uuid', Rule::exists('cash_accounts', 'uuid')],
            'to_account' => ['required', 'uuid', Rule::exists('cash_accounts', 'uuid')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'slip_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $from = CashAccount::query()->where('uuid', $validated['from_account'])->firstOrFail();
        $to = CashAccount::query()->where('uuid', $validated['to_account'])->firstOrFail();
        $user = $request->user();
        $amount = (string) $validated['amount'];
        $note = $validated['note'] ?? null;

        // A cashier moves money out of their own till; only cash.view
        // holders (managers, the owner) move it out of the safe, bank or wallets.
        abort_unless($from->holder_user_id === $user->id || $this->context()->can('cash.view'), 403);

        // Wave 3: the bank and another person's till wait for someone else.
        if ($to->kind === CashAccount::KIND_BANK) {
            $request->validate([
                'reference' => ['required', 'string', 'max:100'],
                'slip_photo' => ['required'],
            ], ['reference.required' => 'Enter the bank deposit slip number.', 'slip_photo.required' => 'Add a photo of the stamped deposit slip.']);

            $deposit = app(CashTransferService::class)->sendDeposit($from, $to, $amount, (string) $validated['reference'], $request->file('slip_photo'), $user, $note);

            return back()->with('success', "{$deposit->number}: in transit until {$deposit->required_confirmations} other person(s) confirm it against the bank.");
        }

        if ($to->isTill() && $to->holder_user_id !== $user->id) {
            $handover = app(CashTransferService::class)->sendHandover($from, $to, $amount, $user, $note);

            return back()->with('success', "{$handover->number}: handed to {$to->holder?->name} — it is theirs once they accept what they count.");
        }

        $number = $this->cashBook->transfer($from, $to, $amount, $user, $validated['reference'] ?? null, $note);

        return back()->with('success', "{$number}: moved to {$to->name}.");
    }

    /** A month's statement balance (bank, wallet) or the safe's count, against the book. */
    public function countStatement(Request $request, CashAccount $cashAccount): RedirectResponse
    {
        $this->authorize('countStatement', $cashAccount);

        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'statement_balance' => ['required', 'numeric', 'min:-999999999.99', 'max:999999999.99', 'decimal:0,2'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $count = $this->cashBook->recordStatementCount($cashAccount, $validated['period'], number_format((float) $validated['statement_balance'], 2, '.', ''), $request->file('photo'), $validated['note'] ?? null, $request->user());

        return back()->with('success', bccomp((string) $count->difference, '0', 2) === 0
            ? "{$validated['period']}: the statement matches the book."
            : "{$validated['period']}: the statement differs from the book by ".number_format((float) $count->difference, 0, '.', ' ').' FCFA — it stays on the alerts until found.');
    }

    /** Repaid into the settler's till, or written off by a manager — never by its owner. */
    public function settleShortage(Request $request, CashShortage $cashShortage): RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in(ShortageService::OUTCOMES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        app(ShortageService::class)->settle($cashShortage, $validated['outcome'], $request->user(), $validated['note'] ?? null);

        return back()->with('success', $validated['outcome'] === CashShortage::STATUS_REPAID ? 'Repayment taken into your till.' : 'Shortage written off.');
    }

    public function closeDay(Request $request, CashAccount $cashAccount): RedirectResponse
    {
        $this->authorize('closeDay', $cashAccount);

        $validated = $request->validate([
            'counted' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $denominations = CashBookService::normalizeDenominations($validated['denominations'] ?? null);

        if ($denominations !== null && bccomp(CashBookService::denominationTotal($denominations), (string) $validated['counted'], 2) !== 0) {
            return back()->withErrors(['counted' => 'The notes and coins you counted don\'t add up to the total.']);
        }

        $close = $this->cashBook->closeDay($cashAccount, number_format((float) $validated['counted'], 2, '.', ''), $denominations, $validated['note'] ?? null, $request->user());

        $variance = (float) $close->variance;
        $closedDate = $close->business_date?->toDateString();
        $day = $closedDate === $this->cashBook->today() ? 'Day' : "{$closedDate}";

        return back()->with('success', $variance === 0.0
            ? "{$day} closed — the count matches the book."
            : "{$day} closed with a difference of ".number_format($variance, 0, '.', ' ').' FCFA. It has been recorded.');
    }

    public function reopenDay(Request $request, CashAccount $cashAccount): RedirectResponse
    {
        $this->authorize('reopenDay', $cashAccount);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $close = CashDayClose::query()->where('cash_account_id', $cashAccount->id)->whereDate('business_date', $this->cashBook->today())->whereNotNull('closed_at')->firstOrFail();

        $this->cashBook->reopenDay($close, $validated['reason'], $request->user());

        return back()->with('success', "{$cashAccount->name} reopened for today.");
    }

    /**
     * @param  array<int, string>  $balances
     * @param  Collection<int, CashDayClose>  $closesToday
     * @return array<string, mixed>
     */
    private function shapeAccount(CashAccount $account, array $balances, $closesToday): array
    {
        $close = $closesToday->get($account->id);
        // Wave 3: the day this till counts next — yesterday's before today's.
        $dayToClose = $account->isTill() ? $this->cashBook->dayToClose($account) : null;

        return [
            'day_to_close' => $dayToClose,
            'close_book' => $dayToClose !== null ? $this->cashBook->balanceOn($account, $dayToClose) : null,
            'can_close' => $account->isTill() && request()->user()->can('closeDay', $account),
            'uuid' => $account->uuid,
            'kind' => $account->kind,
            'name' => $account->name,
            'provider' => $account->provider,
            'account_number' => $account->account_number,
            'holder_name' => $account->holder?->name,
            'balance' => $balances[$account->id] ?? '0.00',
            'closed_today' => $close?->isClosed() ?? false,
            'close' => $close ? [
                'expected' => (string) $close->expected,
                'counted' => (string) $close->counted,
                'variance' => (string) $close->variance,
                'closed_at' => $close->closed_at?->toIso8601String(),
                'reopen_reason' => $close->reopen_reason,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeRemittance(Remittance $remittance): array
    {
        return [
            'uuid' => $remittance->uuid,
            'number' => $remittance->number,
            'collector_name' => $remittance->collector?->name,
            'received_by_name' => $remittance->receivedBy?->name,
            'status' => $remittance->status,
            'expected_cash' => (string) $remittance->expected_cash,
            'expected_mobile' => bcadd((string) $remittance->expected_mtn, (string) $remittance->expected_orange, 2),
            'declared_cash' => (string) $remittance->declared_cash,
            'received_cash' => $remittance->received_cash !== null ? (string) $remittance->received_cash : null,
            'cash_variance' => $remittance->cash_variance !== null ? (string) $remittance->cash_variance : null,
            'shortage_status' => $remittance->shortage_status,
            'submitted_at' => $remittance->submitted_at?->toIso8601String(),
            'received_at' => $remittance->received_at?->toIso8601String(),
        ];
    }

    /**
     * Resolved per call, not injected: the router keeps a controller instance
     * for the life of the app, and the tenant context is per request.
     */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
