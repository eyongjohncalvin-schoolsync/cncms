<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashShortage;
use App\Models\CashTransfer;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CashAlertService;
use App\Services\CashBookService;
use App\Services\CashTransferService;
use App\Services\RemittanceService;
use App\Services\ShortageService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cash on the phone (docs/plans/mobile-manager-app.md, wave 4): the
 * cashier's and manager's daily cash work — receive a collector's
 * handover, accept money handed to them, check bank deposits, close the
 * day, settle shortages, read the cash alerts. Every action calls the same
 * service and policy as the web cash book (App\Http\Controllers\
 * CashBookController, RemittanceController, CashTransferController);
 * moving money between accounts, statements and branch transfers stay on
 * the web. Online only.
 */
class CashController extends Controller
{
    private const array AMOUNT = ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'];

    public function __construct(
        private readonly CashBookService $cashBook,
        private readonly RemittanceService $remittances,
        private readonly CashTransferService $transfers,
    ) {}

    public function index(Request $request, CashAlertService $alerts): JsonResponse
    {
        $user = $request->user();
        $context = $this->context();
        $viewAll = $user->can('viewAny', CashAccount::class);
        $receives = $context->can('cash.receive');

        abort_unless($viewAll || $receives, 403);

        $till = $receives ? $this->cashBook->tillFor($user) : null;

        return response()->json(['data' => [
            'today' => $this->cashBook->today(),
            'can' => ['receive' => $receives, 'view_all' => $viewAll],
            'my_till' => $till ? $this->shapeTill($till, $user) : null,
            'to_receive' => $receives
                ? Remittance::query()->with('collector:id,name')->where('status', 'submitted')->oldest('submitted_at')->get()
                    ->map(fn (Remittance $remittance): array => [
                        ...$this->shapeRemittance($remittance),
                        'can_receive' => $user->can('receive', $remittance),
                    ])->values()->all()
                : [],
            'transfers' => CashTransfer::query()->inTransit()->with(['fromAccount', 'toAccount', 'sentBy', 'toUser', 'confirmations'])
                ->when(! $viewAll, fn ($query) => $query->where(fn ($mine) => $mine->where('sent_by', $user->id)->orWhere('to_user_id', $user->id)))
                ->orderBy('sent_at')->get()
                ->map(fn (CashTransfer $transfer): array => $this->shapeTransfer($transfer, $user))
                // Only what this person can act on: the rest is the web cash book's.
                ->filter(fn (array $transfer): bool => $transfer['can_accept'] || $transfer['can_recall'] || $transfer['can_confirm'] || $transfer['can_refuse_deposit'])
                ->values()->all(),
            'shortages' => [
                ...CashShortage::query()->open()->with('owner:id,name')
                    ->when(! $viewAll, fn ($query) => $query->where('owner_user_id', $user->id))
                    ->orderBy('created_at')->get()
                    ->map(fn (CashShortage $shortage): array => [
                        'type' => 'shortage',
                        'uuid' => $shortage->uuid,
                        'label' => CashShortage::KIND_LABELS[$shortage->kind] ?? $shortage->kind,
                        'owner_name' => $shortage->owner?->name,
                        'amount' => (string) $shortage->amount,
                        'note' => $shortage->note,
                        'created_at' => $shortage->created_at?->toIso8601String(),
                        'can_repay' => $shortage->owner_user_id !== $user->id && $receives,
                        'can_write_off' => $shortage->owner_user_id !== $user->id && $context->can('vouchers.approve'),
                    ])->all(),
                ...($viewAll
                    ? Remittance::query()->with('collector:id,name')->where('shortage_status', 'open')->latest('received_at')->get()
                        ->map(fn (Remittance $remittance): array => [
                            'type' => 'remittance',
                            'uuid' => $remittance->uuid,
                            'label' => "Handover {$remittance->number} short",
                            'owner_name' => $remittance->collector?->name,
                            'amount' => $remittance->shortageOwed(),
                            'note' => $remittance->receiver_note,
                            'created_at' => $remittance->received_at?->toIso8601String(),
                            'can_repay' => $user->can('repayShortage', $remittance),
                            'can_write_off' => $user->can('writeOffShortage', $remittance),
                        ])->all()
                    : []),
            ],
            'alerts' => $viewAll
                ? array_map(fn (array $alert): array => [
                    'key' => $alert['key'],
                    'severity' => $alert['severity'],
                    'title' => $alert['title'],
                    'advice' => $alert['advice'],
                    'items' => array_map(fn (array $item): array => [
                        'label' => $item['label'],
                        'detail' => $item['detail'],
                        'amount' => $item['amount'],
                    ], $alert['items']),
                ], $alerts->alerts())
                : [],
        ]]);
    }

    /** One handover to receive: every payment in it, and the bons paid out of it. */
    public function remittance(Request $request, Remittance $remittance): JsonResponse
    {
        $this->authorize('view', $remittance);

        $remittance->load(['collector:id,name', 'lines.payment.customer:id,name', 'lines.payment.receipt', 'bons']);
        $hasBankLines = $remittance->lines->contains(fn (RemittanceLine $line): bool => $line->method === 'bank' && $line->outcome === null);

        return response()->json(['data' => [
            ...$this->shapeRemittance($remittance),
            'collector_note' => $remittance->collector_note,
            'lines' => $remittance->lines->map(fn (RemittanceLine $line): array => [
                'id' => $line->id,
                'customer_name' => $line->payment?->customer?->name,
                'receipt_number' => $line->payment?->receipt?->receipt_number,
                'method' => $line->method,
                'amount' => (string) $line->amount,
                'reference' => $line->reference,
            ])->values()->all(),
            'bons' => $remittance->bons->map(fn (Voucher $bon): array => [
                'number' => $bon->number,
                'payee_name' => $bon->payee_name,
                'amount' => (string) $bon->amount,
            ])->values()->all(),
            'bank_accounts' => $hasBankLines
                ? CashAccount::query()->withoutGlobalScope(BranchScope::class)->where('kind', CashAccount::KIND_BANK)->where('is_active', true)
                    ->where('branch_id', $remittance->branch_id)->orderBy('name')->get(['uuid', 'name'])
                    ->map(fn (CashAccount $account): array => ['uuid' => $account->uuid, 'name' => $account->name])->values()->all()
                : [],
            'can_receive' => $request->user()->can('receive', $remittance),
        ]]);
    }

    public function receive(Request $request, Remittance $remittance): JsonResponse
    {
        $this->authorize('receive', $remittance);

        $validated = $request->validate([
            'received_cash' => self::AMOUNT,
            'confirmed_lines' => ['nullable', 'array'],
            'confirmed_lines.*' => ['integer'],
            'bank_account' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $remittance = $this->remittances->receive(
            $remittance,
            $request->user(),
            $this->money($validated['received_cash']),
            null,
            array_map('intval', $validated['confirmed_lines'] ?? []),
            $validated['note'] ?? null,
            $validated['bank_account'] ?? null,
        );

        $variance = (float) $remittance->cash_variance;
        $notFound = $remittance->lines()->whereIn('method', Payment::NON_CASH_METHODS)->whereNull('outcome')->count();

        return $this->done(($variance < 0
            ? "Received — {$this->fcfa(-$variance)} short. The shortage stays open on the collector."
            : 'Received into your till.')
            .($notFound > 0 ? " {$notFound} MoMo / bank payment(s) not found — the collector owes them until they are found, repaid or written off." : ''));
    }

    public function settleRemittanceShortage(Request $request, Remittance $remittance): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['repaid', 'written_off'])],
            'note' => ['required_if:outcome,written_off', 'nullable', 'string', 'max:500'],
        ]);

        $this->authorize($validated['outcome'] === 'repaid' ? 'repayShortage' : 'writeOffShortage', $remittance);

        $this->remittances->settleShortage($remittance, $validated['outcome'], $request->user(), $validated['note'] ?? null);

        return $this->done($validated['outcome'] === 'repaid' ? 'Shortage repaid into your till.' : 'Shortage written off.');
    }

    public function settleShortage(Request $request, CashShortage $cashShortage, ShortageService $shortages): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in(ShortageService::OUTCOMES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $shortages->settle($cashShortage, $validated['outcome'], $request->user(), $validated['note'] ?? null);

        return $this->done($validated['outcome'] === CashShortage::STATUS_REPAID ? 'Repayment taken into your till.' : 'Shortage written off.');
    }

    public function closeDay(Request $request, CashAccount $cashAccount): JsonResponse
    {
        $this->authorize('closeDay', $cashAccount);

        $validated = $request->validate([
            'counted' => self::AMOUNT,
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $close = $this->cashBook->closeDay($cashAccount, $this->money($validated['counted']), null, $validated['note'] ?? null, $request->user());

        $variance = (float) $close->variance;
        $closedDate = $close->business_date?->toDateString();
        $day = $closedDate === $this->cashBook->today() ? 'Day' : "{$closedDate}";

        return $this->done($variance === 0.0
            ? "{$day} closed — the count matches the book."
            : "{$day} closed with a difference of {$this->fcfa($variance)}. It has been recorded.");
    }

    public function accept(Request $request, CashTransfer $cashTransfer): JsonResponse
    {
        $validated = $request->validate([
            'counted' => self::AMOUNT,
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $transfer = $this->transfers->acceptHandover($cashTransfer, $this->money($validated['counted']), $request->user(), $validated['note'] ?? null);

        return $this->done(bccomp((string) $transfer->received_amount, (string) $transfer->amount, 2) === 0
            ? "{$transfer->number}: accepted into your till."
            : "{$transfer->number}: accepted what you counted — the rest is {$transfer->sentBy?->name}'s shortage.");
    }

    public function refuse(Request $request, CashTransfer $cashTransfer): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $transfer = $this->transfers->refuseHandover($cashTransfer, $validated['reason'], $request->user());

        return $this->done("{$transfer->number}: refused — back in {$transfer->sentBy?->name}'s till.");
    }

    public function recall(Request $request, CashTransfer $cashTransfer): JsonResponse
    {
        $transfer = $this->transfers->recall($cashTransfer, $request->user());

        return $this->done("{$transfer->number}: back in your till.");
    }

    public function confirm(Request $request, CashTransfer $cashTransfer): JsonResponse
    {
        $transfer = $this->transfers->confirmDeposit($cashTransfer, $request->user());

        return $this->done($transfer->status === CashTransfer::STATUS_RECEIVED
            ? "{$transfer->number}: confirmed — the bank account is credited."
            : "{$transfer->number}: your confirmation is recorded — waiting for the next person.");
    }

    public function refuseDeposit(Request $request, CashTransfer $cashTransfer): JsonResponse
    {
        $validated = $request->validate([
            'found' => self::AMOUNT,
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $transfer = $this->transfers->refuseDeposit($cashTransfer, $this->money($validated['found']), $validated['reason'], $request->user());

        return $this->done("{$transfer->number}: refused — what is missing is {$transfer->sentBy?->name}'s shortage.");
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeTill(CashAccount $till, User $user): array
    {
        $dayToClose = $this->cashBook->dayToClose($till);

        return [
            'uuid' => $till->uuid,
            'name' => $till->name,
            'balance' => $this->cashBook->balance($till),
            'day_to_close' => $dayToClose,
            'close_book' => $this->cashBook->balanceOn($till, $dayToClose),
            'closed_today' => $this->cashBook->isDayClosed($till),
            'can_close' => $user->can('closeDay', $till),
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
            'expected_cash' => (string) $remittance->expected_cash,
            'expected_mobile' => bcadd((string) $remittance->expected_mtn, (string) $remittance->expected_orange, 2),
            'expected_bank' => (string) $remittance->expected_bank,
            'bons_total' => (string) $remittance->bons_total,
            'cash_to_hand_over' => $remittance->cashToHandOver(),
            'declared_cash' => (string) $remittance->declared_cash,
            'submitted_at' => $remittance->submitted_at?->toIso8601String(),
        ];
    }

    /**
     * The same rules as the web cash book's list (CashBookController::shapeTransfer()).
     *
     * @return array<string, mixed>
     */
    private function shapeTransfer(CashTransfer $transfer, User $viewer): array
    {
        $isDeposit = $transfer->isDeposit();
        $checksDeposits = $isDeposit && $transfer->sent_by !== $viewer->id && $this->context()->can('cash.view');

        return [
            'uuid' => $transfer->uuid,
            'number' => $transfer->number,
            'kind' => $transfer->kind,
            'from' => $transfer->fromAccount?->name,
            'to' => $transfer->toAccount?->name,
            'sent_by_name' => $transfer->sentBy?->name,
            'amount' => (string) $transfer->amount,
            'slip_number' => $transfer->slip_number,
            'note' => $transfer->note,
            'sent_at' => $transfer->sent_at?->toIso8601String(),
            'confirmations' => $transfer->confirmations->count(),
            'required_confirmations' => $transfer->required_confirmations,
            'can_accept' => ! $isDeposit && $transfer->to_user_id === $viewer->id,
            'can_recall' => ! $isDeposit && $transfer->sent_by === $viewer->id,
            'can_confirm' => $checksDeposits && ! $transfer->confirmations->contains('user_id', $viewer->id),
            'can_refuse_deposit' => $checksDeposits,
        ];
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function fcfa(float $amount): string
    {
        return number_format($amount, 0, '.', ' ').' FCFA';
    }

    private function done(string $message): JsonResponse
    {
        return response()->json(['message' => $message]);
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
