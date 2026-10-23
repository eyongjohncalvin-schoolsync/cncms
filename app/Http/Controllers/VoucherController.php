<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CorrectVoucherRequest;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherApproval;
use App\Services\CashBookService;
use App\Services\SpendingControlService;
use App\Services\VoucherService;
use App\Support\AmountInWords;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Vouchers / bons de caisse (docs/plans/cash-controls.md, wave 5) —
 * request, approve (within limits, or by every stakeholder above them), pay,
 * settle an advance, and print the signed voucher. Rules live in
 * App\Services\VoucherService and App\Policies\VoucherPolicy.
 */
class VoucherController extends Controller
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly CashBookService $cashBook,
        private readonly SpendingControlService $spending,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Voucher::class);

        $user = $request->user();
        $context = app(TenantContext::class);
        $seesAll = $context->canAny('vouchers.approve', 'vouchers.pay', 'cash.view');

        $vouchers = Voucher::query()
            ->with('requestedBy')
            ->when(! $seesAll, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('requested_by', $user->id)
                ->orWhereJsonContains('required_approver_ids', $user->id)))
            ->latest('id')
            ->limit(200)
            ->get();

        $decidedByMe = VoucherApproval::query()->where('user_id', $user->id)->pluck('voucher_id')->all();
        $company = Company::cached();

        return Inertia::render('Vouchers/Index', [
            'vouchers' => $vouchers->map(fn (Voucher $voucher): array => [
                ...$this->shapeRow($voucher),
                'awaiting_me' => $voucher->isPending() && ! in_array($voucher->id, $decidedByMe, true) && $user->can('decide', $voucher),
                'ready_to_pay' => $user->can('pay', $voucher),
            ])->values()->all(),
            'can_request' => $user->can('create', Voucher::class),
            'limits' => [
                'single' => $company?->voucher_approval_limit !== null ? (string) $company->voucher_approval_limit : null,
                'daily' => $company?->voucher_daily_limit !== null ? (string) $company->voucher_daily_limit : null,
                // E8: the day counts bons by the day they are PAID, plus the
                // approved ones still waiting to be paid.
                'committed_today' => bcadd($this->vouchers->paidToday(), (string) Voucher::query()->where('status', 'approved')->sum('amount'), 2),
            ],
            // Monthly cap + per-category budgets for the bon form's warnings
            // (docs/plans/expenditures-and-spending-controls.md, items 4–5).
            'spending' => $this->spending->summary(BusinessTimezone::currentPeriod()),
            'can_spend_from_collections' => $context->can('cash.remit'),
            'categories' => ExpenseCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['uuid', 'name'])->toArray(),
            'today' => $this->cashBook->today(),
            'is_investor' => (bool) $context->tenantUser->is_investor,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Voucher::class);

        $validated = $request->validate([
            'kind' => ['required', Rule::in([Voucher::KIND_EXPENSE, Voucher::KIND_ADVANCE])],
            'payee_name' => ['required', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:500'],
            'category_uuid' => ['required_if:kind,expense', 'nullable', 'uuid', Rule::exists('expense_categories', 'uuid')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'needed_on' => ['nullable', 'date', 'after_or_equal:'.BusinessTimezone::today()],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'from_collections' => ['nullable', 'boolean'],
        ]);

        if (($validated['from_collections'] ?? false) && ! app(TenantContext::class)->can('cash.remit')) {
            abort(403, 'Only a collector can pay a bon out of their own collections.');
        }

        $validated['from_collections'] = (bool) ($validated['from_collections'] ?? false);

        $voucher = $this->vouchers->request([
            ...$validated,
            'amount' => number_format((float) $validated['amount'], 2, '.', ''),
            'needed_on' => isset($validated['needed_on']) ? substr((string) $validated['needed_on'], 0, 10) : null,
        ], $request->user(), $request->file('attachment'));

        return redirect()->route('vouchers.show', $voucher)->with('success', $voucher->needsStakeholders()
            ? "{$voucher->number} is above the company's limits — every stakeholder has been asked to approve it."
            : "{$voucher->number} sent for approval.");
    }

    public function show(Request $request, Voucher $voucher): Response
    {
        $this->authorize('view', $voucher);

        $voucher->load(['requestedBy', 'paidBy', 'settledBy', 'category', 'paidFrom', 'approvals.user', 'remittance', 'expenditures']);
        $user = $request->user();
        $context = app(TenantContext::class);

        $approvedIds = $voucher->approvals->where('decision', 'approved')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $required = User::query()->whereIn('id', $voucher->requiredApproverIds())->get(['id', 'name']);

        return Inertia::render('Vouchers/Show', [
            'voucher' => [
                ...$this->shapeRow($voucher),
                'purpose' => $voucher->purpose,
                'category_name' => $voucher->category?->name,
                'amount_words' => ['en' => AmountInWords::convert((string) $voucher->amount), 'fr' => AmountInWords::french((string) $voucher->amount)],
                'tier_reasons' => $voucher->tier_reasons ?? [],
                'approval_mode' => $voucher->approval_mode,
                'has_attachment' => $voucher->attachment_path !== null,
                'has_signed_copy' => $voucher->signed_copy_path !== null,
                'rejection_reason' => $voucher->rejection_reason,
                'paid_by_name' => $voucher->paidBy?->name,
                'paid_at' => $voucher->paid_at?->toIso8601String(),
                'paid_from_name' => $voucher->paidFrom?->name,
                'payment_method' => $voucher->payment_method,
                'payment_reference' => $voucher->payment_reference,
                'received_by_name' => $voucher->received_by_name,
                'settle_due_on' => $voucher->settle_due_on?->toDateString(),
                'spent_amount' => $voucher->spent_amount !== null ? (string) $voucher->spent_amount : null,
                'returned_amount' => $voucher->returned_amount !== null ? (string) $voucher->returned_amount : null,
                'settled_by_name' => $voucher->settledBy?->name,
                'settlement_note' => $voucher->settlement_note,
                'category_uuid' => $voucher->category?->uuid,
                'from_collections' => (bool) $voucher->from_collections,
                'remittance' => $voucher->remittance ? ['uuid' => $voucher->remittance->uuid, 'number' => $voucher->remittance->number] : null,
                'has_receipt' => $voucher->receipt_path !== null,
                'expenditures' => $voucher->expenditures->map(fn ($row): array => [
                    'uuid' => $row->uuid,
                    'amount' => (string) $row->amount,
                    'spent_at' => $row->spent_at?->toDateString(),
                    'source' => $row->source,
                    'status' => $row->status,
                ])->values()->all(),
                'approvals' => $voucher->approvals->map(fn (VoucherApproval $approval): array => [
                    'name' => $approval->user?->name,
                    'decision' => $approval->decision,
                    'note' => $approval->note,
                    'decided_at' => $approval->decided_at?->toIso8601String(),
                ])->values()->all(),
                'stakeholders' => $required->map(fn (User $stakeholder): array => [
                    'name' => $stakeholder->name,
                    'approved' => in_array($stakeholder->id, $approvedIds, true),
                ])->values()->all(),
            ],
            'can' => [
                'decide' => $user->can('decide', $voucher),
                'cancel' => $user->can('cancel', $voucher),
                'pay' => $user->can('pay', $voucher),
                'settle' => $user->can('settle', $voucher),
                'correct' => $user->can('correct', $voucher),
            ],
            'categories' => ExpenseCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['uuid', 'name'])->toArray(),
            'pay_accounts' => $user->can('pay', $voucher) || $user->can('settle', $voucher)
                ? $this->payAccounts($user, $context)
                : [],
            'is_investor' => (bool) $context->tenantUser->is_investor,
        ]);
    }

    public function approve(Request $request, Voucher $voucher): RedirectResponse
    {
        $this->authorize('decide', $voucher);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $outcome = $this->vouchers->approve($voucher, $request->user(), $validated['note'] ?? null);

        return back()->with('success', match ($outcome) {
            'approved' => "{$voucher->number} approved — it can now be paid.",
            'recorded' => 'Your approval is recorded. The other stakeholders still need to approve.',
            default => 'Payouts approved since this request have taken the day over its limit — it now needs every stakeholder\'s approval, and they have been asked.',
        });
    }

    public function reject(Request $request, Voucher $voucher): RedirectResponse
    {
        $this->authorize('decide', $voucher);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->vouchers->reject($voucher, $request->user(), $validated['reason']);

        return back()->with('success', "{$voucher->number} rejected.");
    }

    public function cancel(Voucher $voucher): RedirectResponse
    {
        $this->authorize('cancel', $voucher);

        $this->vouchers->cancel($voucher);

        return back()->with('success', "{$voucher->number} cancelled.");
    }

    public function pay(Request $request, Voucher $voucher): RedirectResponse
    {
        $this->authorize('pay', $voucher);

        $validated = $request->validate([
            'account' => ['required', 'uuid', Rule::in(array_column($this->payAccounts($request->user(), app(TenantContext::class)), 'uuid'))],
            'method' => ['required', Rule::in(['cash', 'mtn_momo', 'orange_money', 'bank'])],
            'reference' => ['nullable', 'required_unless:method,cash', 'string', 'max:100'],
            'received_by_name' => ['required', 'string', 'max:150'],
            'signed_copy' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $this->vouchers->pay(
            $voucher,
            $request->user(),
            CashAccount::query()->where('uuid', $validated['account'])->firstOrFail(),
            $validated['method'],
            $validated['reference'] ?? null,
            $validated['received_by_name'],
            $request->file('signed_copy'),
        );

        return back()->with('success', "{$voucher->number} paid to {$validated['received_by_name']}. Print the voucher and have it signed.");
    }

    public function settle(Request $request, Voucher $voucher): RedirectResponse
    {
        $this->authorize('settle', $voucher);

        $validated = $request->validate([
            'spent_amount' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'returned_amount' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
            'category_uuid' => ['nullable', 'uuid', Rule::exists('expense_categories', 'uuid')],
        ]);

        $this->vouchers->settle(
            $voucher,
            $request->user(),
            number_format((float) $validated['spent_amount'], 2, '.', ''),
            number_format((float) $validated['returned_amount'], 2, '.', ''),
            $validated['note'] ?? null,
            $validated['category_uuid'] ?? null,
        );

        return back()->with('success', "{$voucher->number} settled.");
    }

    /**
     * Correct a paid bon (docs/plans/expenditures-and-spending-controls.md,
     * item 9): money returned → cash back into the till and a negative
     * adjustment this month; underpaid → a new bon for the difference.
     */
    public function correct(CorrectVoucherRequest $request, Voucher $voucher): RedirectResponse
    {
        $validated = $request->validated();

        $result = $this->vouchers->correct(
            $voucher,
            $request->user(),
            $validated['outcome'],
            number_format((float) $validated['amount'], 2, '.', ''),
            $validated['reason'],
        );

        if ($result['outcome'] === 'underpaid') {
            return redirect()->route('vouchers.show', $result['voucher'])
                ->with('success', "{$result['voucher']->number} raised for the difference — it goes through approval like any bon.");
        }

        return back()->with('success', 'Correction recorded: the money is back in your till and this month\'s expenses are reduced.');
    }

    /**
     * The photo of the printed voucher signed by the payee — normally taken
     * after paying, once it has been printed and signed.
     */
    public function uploadSignedCopy(Request $request, Voucher $voucher): RedirectResponse
    {
        abort_unless(in_array($voucher->status, ['paid', 'settled'], true) && app(TenantContext::class)->can('vouchers.pay'), 403);

        $validated = $request->validate(['signed_copy' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']]);

        $voucher->update(['signed_copy_path' => $validated['signed_copy']->store('vouchers', $this->vouchers->disk())]);

        return back()->with('success', 'Signed copy attached.');
    }

    public function pdf(Voucher $voucher): HttpResponse
    {
        $this->authorize('view', $voucher);

        $voucher->load(['requestedBy', 'paidBy', 'category', 'paidFrom', 'approvals.user']);
        $company = Company::cached();

        return Pdf::loadView('pdf.voucher', [
            'voucher' => $voucher,
            'company' => $company,
            'logo' => $company?->logoDataUri(),
            'words' => ['en' => AmountInWords::convert((string) $voucher->amount), 'fr' => AmountInWords::french((string) $voucher->amount)],
        ])->setPaper('a5', 'landscape')->download("voucher-{$voucher->number}.pdf");
    }

    public function file(Voucher $voucher, string $which): HttpResponse
    {
        $this->authorize('view', $voucher);

        $path = match ($which) {
            'attachment' => $voucher->attachment_path,
            'signed-copy' => $voucher->signed_copy_path,
            'receipt' => $voucher->receipt_path,
            default => null,
        };

        abort_if($path === null || ! Storage::disk($this->vouchers->disk())->exists($path), 404);

        return Storage::disk($this->vouchers->disk())->download($path);
    }

    /**
     * Where the payer may pay from: their own till, plus — for anyone who
     * sees the whole cash book — the safe, bank and wallets.
     *
     * @return list<array{uuid: string, name: string, balance: string}>
     */
    private function payAccounts(User $user, TenantContext $context): array
    {
        $accounts = collect([$this->cashBook->tillFor($user)]);

        if ($context->can('cash.view')) {
            $accounts = $accounts->merge(CashAccount::query()->where('is_active', true)->where('kind', '!=', CashAccount::KIND_TILL)->get());
        }

        $balances = $this->cashBook->balances($accounts->pluck('id')->all());

        return $accounts->map(fn (CashAccount $account): array => [
            'uuid' => $account->uuid,
            'name' => $account->name,
            'balance' => $balances[$account->id] ?? '0.00',
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeRow(Voucher $voucher): array
    {
        return [
            'uuid' => $voucher->uuid,
            'number' => $voucher->number,
            'kind' => $voucher->kind,
            'payee_name' => $voucher->payee_name,
            'purpose' => $voucher->purpose,
            'amount' => (string) $voucher->amount,
            'needed_on' => $voucher->needed_on?->toDateString(),
            'status' => $voucher->status,
            'approval_tier' => $voucher->approval_tier,
            'requested_by_name' => $voucher->requestedBy?->name,
            'created_at' => $voucher->created_at?->toIso8601String(),
            'overdue_settlement' => $voucher->kind === Voucher::KIND_ADVANCE && $voucher->status === 'paid' && $voucher->settle_due_on?->isPast(),
        ];
    }
}
