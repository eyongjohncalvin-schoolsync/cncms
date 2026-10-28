<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Models\Scopes\BranchScope;
use App\Models\Voucher;
use App\Services\CashBookService;
use App\Services\RemittanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The daily income form on the web (docs/plans/cash-controls.md, wave 4):
 * a collector hands over what they collected; a cashier receives it.
 * See App\Services\RemittanceService for the rules.
 */
class RemittanceController extends Controller
{
    public function __construct(
        private readonly RemittanceService $remittances,
    ) {}

    public function create(Request $request): Response
    {
        $this->authorize('create', Remittance::class);

        $preview = $this->remittances->preview($request->user());

        return Inertia::render('Cash/HandOver', [
            'payments' => $preview['payments']->map(fn (Payment $payment): array => [
                'uuid' => $payment->uuid,
                'customer_name' => $payment->customer?->name,
                'amount' => (string) $payment->amount,
                'method' => $payment->method ?? 'cash',
                'purpose' => $payment->purpose ?? Payment::PURPOSE_SUBSCRIPTION,
                'method_reference' => $payment->method_reference,
                'receipt_number' => $payment->receipt?->receipt_number,
                'verification_status' => $payment->verification_status,
                'recorded_at' => $payment->created_at?->toIso8601String(),
            ])->values()->all(),
            'totals' => $preview['totals'],
            'purpose_totals' => $preview['purpose_totals'],
            'oldest_at' => $preview['oldest_at'],
            // Approved bons already paid out of these collections — the
            // cashier pays them as part of receiving, so hand over the rest.
            'bons' => $preview['bons']->map(fn (Voucher $bon): array => $this->shapeBon($bon))->values()->all(),
            'bons_total' => $preview['bons_total'],
            'cash_to_hand_over' => $preview['cash_to_hand_over'],
            'history' => Remittance::query()->where('collector_user_id', $request->user()->id)->latest('submitted_at')->limit(10)->get()
                ->map(fn (Remittance $remittance): array => [
                    'uuid' => $remittance->uuid,
                    'number' => $remittance->number,
                    'status' => $remittance->status,
                    'expected_cash' => (string) $remittance->expected_cash,
                    'declared_cash' => (string) $remittance->declared_cash,
                    'received_cash' => $remittance->received_cash !== null ? (string) $remittance->received_cash : null,
                    'cash_variance' => $remittance->cash_variance !== null ? (string) $remittance->cash_variance : null,
                    'shortage_status' => $remittance->shortage_status,
                    'submitted_at' => $remittance->submitted_at?->toIso8601String(),
                ])->values()->all(),
            'denominations' => CashBookService::DENOMINATIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Remittance::class);

        $validated = $this->validateCount($request, 'declared_cash');

        $remittance = $this->remittances->submit(
            $request->user(),
            number_format((float) $validated['declared_cash'], 2, '.', ''),
            CashBookService::normalizeDenominations($validated['denominations'] ?? null),
            $validated['note'] ?? null,
        );

        return redirect()->route('cash.remittances.show', $remittance)
            ->with('success', "{$remittance->number} submitted. Hand the cash to the cashier — they will count it and confirm.");
    }

    public function show(Request $request, Remittance $remittance): Response
    {
        $this->authorize('view', $remittance);

        $remittance->load(['collector', 'receivedBy', 'till', 'lines.payment.customer', 'lines.payment.receipt', 'lines.account', 'lines.settledBy', 'bons']);
        $hasBankLines = $remittance->lines->contains(fn (RemittanceLine $line): bool => $line->method === 'bank' && $line->outcome === null);

        return Inertia::render('Cash/Remittance', [
            'remittance' => [
                'uuid' => $remittance->uuid,
                'number' => $remittance->number,
                'status' => $remittance->status,
                'collector_name' => $remittance->collector?->name,
                'business_date' => $remittance->business_date?->toDateString(),
                'expected' => [
                    'cash' => (string) $remittance->expected_cash,
                    'mtn_momo' => (string) $remittance->expected_mtn,
                    'orange_money' => (string) $remittance->expected_orange,
                    'bank' => (string) $remittance->expected_bank,
                ],
                'bons' => $remittance->bons->map(fn (Voucher $bon): array => $this->shapeBon($bon))->values()->all(),
                'bons_total' => (string) $remittance->bons_total,
                'cash_to_hand_over' => $remittance->cashToHandOver(),
                'declared_cash' => (string) $remittance->declared_cash,
                'declared_denominations' => $remittance->declared_denominations,
                'collector_note' => $remittance->collector_note,
                'submitted_at' => $remittance->submitted_at?->toIso8601String(),
                'received_by_name' => $remittance->receivedBy?->name,
                'received_at' => $remittance->received_at?->toIso8601String(),
                'till_name' => $remittance->till?->name,
                'received_cash' => $remittance->received_cash !== null ? (string) $remittance->received_cash : null,
                'received_denominations' => $remittance->received_denominations,
                'cash_variance' => $remittance->cash_variance !== null ? (string) $remittance->cash_variance : null,
                'receiver_note' => $remittance->receiver_note,
                'shortage_status' => $remittance->shortage_status,
                'shortage_note' => $remittance->shortage_note,
                // What is still owed after the collector's earlier extra cash covered part of it.
                'shortage_owed' => $remittance->shortageOwed(),
                // Extra cash handed in here, kept as the collector's credit for later shortages.
                'credit_remaining' => $remittance->credit_remaining !== null ? (string) $remittance->credit_remaining : null,
                // Cash taken at the desk, straight into the recorder's till (RemittanceService::receiveAtDesk()).
                'at_desk' => $remittance->at_desk,
                'lines' => $remittance->lines->map(fn (RemittanceLine $line): array => [
                    'id' => $line->id,
                    'payment_uuid' => $line->payment?->uuid,
                    'customer_name' => $line->payment?->customer?->name,
                    'receipt_number' => $line->payment?->receipt?->receipt_number,
                    'method' => $line->method,
                    'amount' => (string) $line->amount,
                    'reference' => $line->reference,
                    'wallet_confirmed' => $line->wallet_confirmed,
                    'outcome' => $line->outcome,
                    'account_name' => $line->account?->name,
                    'settled_by_name' => $line->settledBy?->name,
                    'settled_at' => $line->settled_at?->toIso8601String(),
                    'settled_note' => $line->settled_note,
                    'purpose' => $line->payment?->purpose ?? Payment::PURPOSE_SUBSCRIPTION,
                ])->values()->all(),
                // Technician fees are counted with the cash but belong to the
                // technician (D13) — the cashier pays them out from the till.
                'purpose_totals' => $this->remittances->totalsByPurpose($remittance->lines->map(fn (RemittanceLine $line): ?Payment => $line->payment)->filter()),
            ],
            'can' => [
                'receive' => $request->user()->can('receive', $remittance),
                'repay_shortage' => $request->user()->can('repayShortage', $remittance),
                'write_off_shortage' => $request->user()->can('writeOffShortage', $remittance),
                'settle_line' => [
                    'found' => $request->user()->can('settleLine', [$remittance, RemittanceLine::OUTCOME_FOUND]),
                    'repaid' => $request->user()->can('settleLine', [$remittance, RemittanceLine::OUTCOME_REPAID]),
                    'written_off' => $request->user()->can('settleLine', [$remittance, RemittanceLine::OUTCOME_WRITTEN_OFF]),
                ],
            ],
            // A bank payment is ticked into one of the branch's bank accounts.
            'bank_accounts' => $hasBankLines
                ? CashAccount::query()->withoutGlobalScope(BranchScope::class)->where('kind', CashAccount::KIND_BANK)->where('is_active', true)
                    ->where('branch_id', $remittance->branch_id)->orderBy('name')->get(['uuid', 'name'])
                    ->map(fn (CashAccount $account): array => ['uuid' => $account->uuid, 'name' => $account->name])->values()->all()
                : [],
            'denominations' => CashBookService::DENOMINATIONS,
        ]);
    }

    public function receive(Request $request, Remittance $remittance): RedirectResponse
    {
        $this->authorize('receive', $remittance);

        $validated = $this->validateCount($request, 'received_cash', [
            'confirmed_lines' => ['nullable', 'array'],
            'confirmed_lines.*' => ['integer'],
            'bank_account' => ['nullable', 'uuid'],
        ]);

        $remittance = $this->remittances->receive(
            $remittance,
            $request->user(),
            number_format((float) $validated['received_cash'], 2, '.', ''),
            CashBookService::normalizeDenominations($validated['denominations'] ?? null),
            array_map('intval', $validated['confirmed_lines'] ?? []),
            $validated['note'] ?? null,
            $validated['bank_account'] ?? null,
        );

        $variance = (float) $remittance->cash_variance;
        $notFound = $remittance->lines()->whereIn('method', Payment::NON_CASH_METHODS)->whereNull('outcome')->count();

        return back()->with('success', ($variance < 0
            ? 'Received — '.number_format(-$variance, 0, '.', ' ').' FCFA short. The shortage stays open on the collector.'
            : 'Received into your till.')
            .($notFound > 0 ? " {$notFound} MoMo / bank payment(s) not found — the collector owes them until they are found, repaid or written off." : ''));
    }

    /** RemittanceService::settleLine(). */
    public function settleLine(Request $request, Remittance $remittance, RemittanceLine $line): RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in([RemittanceLine::OUTCOME_FOUND, RemittanceLine::OUTCOME_REPAID, RemittanceLine::OUTCOME_WRITTEN_OFF])],
            'note' => ['required_if:outcome,written_off', 'nullable', 'string', 'max:500'],
            'bank_account' => ['nullable', 'uuid'],
        ]);

        $this->authorize('settleLine', [$remittance, $validated['outcome']]);

        $this->remittances->settleLine($line, $validated['outcome'], $request->user(), $validated['note'] ?? null, $validated['bank_account'] ?? null);

        return back()->with('success', match ($validated['outcome']) {
            RemittanceLine::OUTCOME_FOUND => 'Found — booked into the account.',
            RemittanceLine::OUTCOME_REPAID => 'Paid in cash — received into your till.',
            default => 'Written off.',
        });
    }

    public function settleShortage(Request $request, Remittance $remittance): RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['repaid', 'written_off'])],
            'note' => ['required_if:outcome,written_off', 'nullable', 'string', 'max:500'],
        ]);

        $this->authorize($validated['outcome'] === 'repaid' ? 'repayShortage' : 'writeOffShortage', $remittance);

        $this->remittances->settleShortage($remittance, $validated['outcome'], $request->user(), $validated['note'] ?? null);

        return back()->with('success', $validated['outcome'] === 'repaid' ? 'Shortage repaid into your till.' : 'Shortage written off.');
    }

    /**
     * @return array{uuid: string, number: string, payee_name: string, purpose: string, amount: string, status: string}
     */
    private function shapeBon(Voucher $bon): array
    {
        return [
            'uuid' => $bon->uuid,
            'number' => $bon->number,
            'payee_name' => $bon->payee_name,
            'purpose' => $bon->purpose,
            'amount' => (string) $bon->amount,
            'status' => $bon->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validateCount(Request $request, string $field, array $extra = []): array
    {
        return $request->validate([
            $field => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
            ...$extra,
        ]);
    }
}
