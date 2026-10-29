<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\CashAccount;
use App\Services\BranchTransferService;
use App\Services\CashBookService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money between branches (branches v2 §4.7, D6): send it from one of your
 * branch's accounts, see it in transit, and — at the receiving branch — put
 * it into one of your accounts or refuse it. Everyone with `cash.receive`;
 * a fenced user only sends from, and receives into, their own branch.
 */
class BranchTransferController extends Controller
{
    public function __construct(
        private readonly BranchTransferService $transfers,
        private readonly CashBookService $cashBook,
    ) {}

    public function index(): Response
    {
        $this->authorize('transfer', CashAccount::class);

        $myBranchId = TenantContext::currentBranchId();
        $balances = $this->cashBook->balances(null);

        return Inertia::render('Cash/BranchTransfers', [
            'my_branch_uuid' => $myBranchId !== null ? Branch::allMemoized()->get($myBranchId)?->uuid : null,
            // Accounts the money can leave from / arrive in: the user's branch's (scoped).
            'accounts' => $this->cashBook->activeAccounts()->map(fn (CashAccount $account): array => [
                'uuid' => $account->uuid,
                'name' => $account->name,
                'branch_uuid' => Branch::allMemoized()->get($account->branch_id)?->uuid,
                'balance' => (string) ($balances[$account->id] ?? '0.00'),
            ])->values()->all(),
            'branches' => Branch::allMemoized()->sortByDesc('is_headquarters')->map(fn (Branch $branch): array => [
                'uuid' => $branch->uuid,
                'name' => $branch->name,
                'is_headquarters' => $branch->is_headquarters,
            ])->values()->all(),
            'transfers' => BranchTransfer::query()->visible()
                ->with(['fromBranch:id,name', 'toBranch:id,name', 'fromAccount:id,name', 'toAccount:id,name', 'sentBy:id,name', 'receivedBy:id,name'])
                ->orderByRaw("status = 'sent' desc")->latest('sent_at')->limit(50)->get()
                ->map(fn (BranchTransfer $transfer): array => [
                    'uuid' => $transfer->uuid,
                    'number' => $transfer->number,
                    'from' => $transfer->fromBranch?->name,
                    'to' => $transfer->toBranch?->name,
                    'to_branch_uuid' => Branch::allMemoized()->get($transfer->to_branch_id)?->uuid,
                    'from_account' => $transfer->fromAccount?->name,
                    'to_account' => $transfer->toAccount?->name,
                    'amount' => (string) $transfer->amount,
                    'received_amount' => $transfer->received_amount !== null ? (string) $transfer->received_amount : null,
                    'status' => $transfer->status,
                    'note' => $transfer->note,
                    'receive_note' => $transfer->receive_note,
                    'sent_by' => $transfer->sentBy?->name,
                    'sent_at' => $transfer->sent_at?->toIso8601String(),
                    'received_by' => $transfer->receivedBy?->name,
                    'received_at' => $transfer->received_at?->toIso8601String(),
                    'can_receive' => $transfer->isInTransit() && $this->receivesFor($transfer) && $transfer->sent_by !== request()->user()->id,
                ])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('transfer', CashAccount::class);

        $validated = $request->validate([
            'from_account' => ['required', 'uuid'],
            'to_branch' => ['required', 'uuid', Rule::exists('branches', 'uuid')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Scoped: a fenced user can only send from their own branch's accounts.
        $from = CashAccount::query()->where('uuid', $validated['from_account'])->where('is_active', true)->firstOrFail();
        $to = Branch::query()->where('uuid', $validated['to_branch'])->firstOrFail();

        // As for a transfer within the branch: a cashier sends from their own
        // till; only cash.view holders send from other accounts.
        abort_unless($from->holder_user_id === $request->user()->id || app(TenantContext::class)->can('cash.view'), 403);

        $transfer = $this->transfers->send($from, $to, (string) $validated['amount'], $validated['note'] ?? null, $request->user());

        return redirect()->route('cash.branch-transfers')->with('success', "{$transfer->number}: sent to {$to->name} — in transit until they receive it.");
    }

    public function receive(Request $request, BranchTransfer $branchTransfer): RedirectResponse
    {
        $this->authorize('transfer', CashAccount::class);
        abort_unless($this->receivesFor($branchTransfer), 403);

        $validated = $request->validate([
            'account' => ['required', 'uuid'],
            // What was counted on arrival (owner, 2026-10-28) — the whole amount when not given.
            'received_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $into = CashAccount::query()->where('uuid', $validated['account'])->where('is_active', true)->firstOrFail();
        $received = isset($validated['received_amount']) ? number_format((float) $validated['received_amount'], 2, '.', '') : null;

        $this->transfers->receive($branchTransfer, $into, $validated['note'] ?? null, $request->user(), $received);

        return redirect()->route('cash.branch-transfers')->with('success', "{$branchTransfer->number}: received into {$into->name}.");
    }

    public function reject(Request $request, BranchTransfer $branchTransfer): RedirectResponse
    {
        $this->authorize('transfer', CashAccount::class);
        abort_unless($this->receivesFor($branchTransfer), 403);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->transfers->reject($branchTransfer, $validated['reason'], $request->user());

        return redirect()->route('cash.branch-transfers')->with('success', "{$branchTransfer->number}: refused — returned to the sender.");
    }

    /** The receiving branch's own staff, or anyone not fenced to a branch. */
    private function receivesFor(BranchTransfer $transfer): bool
    {
        $context = app(TenantContext::class);

        return ! $context->isFenced() || $context->branchId === $transfer->to_branch_id;
    }
}
