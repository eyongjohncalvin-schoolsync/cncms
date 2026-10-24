<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashDayClose;
use App\Models\CashMovement;
use App\Models\Remittance;
use App\Models\Scopes\BranchScope;
use App\Services\CashBookService;
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

        $accounts = $canViewAll ? $this->cashBook->activeAccounts() : collect();
        $myTill = $canReceive ? $this->cashBook->tillFor($user) : null;
        $balances = $this->cashBook->balances(null);

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
            'transfer_targets' => $this->cashBook->activeAccounts()->map(fn (CashAccount $account): array => [
                'uuid' => $account->uuid,
                'name' => $account->name,
                'kind' => $account->kind,
            ])->values()->all(),
            'awaiting' => Remittance::query()->with('collector')->where('status', 'submitted')->latest('submitted_at')->get()
                ->map(fn (Remittance $remittance): array => $this->shapeRemittance($remittance))->values()->all(),
            'open_shortages' => $canViewAll
                ? Remittance::query()->with('collector')->where('shortage_status', 'open')->latest('received_at')->get()
                    ->map(fn (Remittance $remittance): array => $this->shapeRemittance($remittance))->values()->all()
                : [],
            'recent' => $canViewAll
                ? Remittance::query()->with(['collector', 'receivedBy'])->where('status', 'received')->latest('received_at')->limit(20)->get()
                    ->map(fn (Remittance $remittance): array => $this->shapeRemittance($remittance))->values()->all()
                : [],
            'denominations' => CashBookService::DENOMINATIONS,
        ]);
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
            'can_set_opening_balance' => $request->user()->can('update', $cashAccount) && $movements->isEmpty(),
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
        ]);

        $from = CashAccount::query()->where('uuid', $validated['from_account'])->firstOrFail();
        $to = CashAccount::query()->where('uuid', $validated['to_account'])->firstOrFail();

        // A cashier moves money out of their own till; only cash.view
        // holders (managers, the owner) move it out of other accounts.
        abort_unless($from->holder_user_id === $request->user()->id || $this->context()->can('cash.view'), 403);

        $number = $this->cashBook->transfer($from, $to, (string) $validated['amount'], $request->user(), $validated['reference'] ?? null, $validated['note'] ?? null);

        return back()->with('success', "{$number}: moved to {$to->name}.");
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

        return back()->with('success', $variance === 0.0
            ? 'Day closed — the count matches the book.'
            : 'Day closed with a difference of '.number_format($variance, 0, '.', ' ').' FCFA. It has been recorded.');
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

        return [
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
