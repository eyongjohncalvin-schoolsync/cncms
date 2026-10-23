<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RejectOpeningBalanceBatchRequest;
use App\Http\Requests\SaveOpeningBalanceEntriesRequest;
use App\Http\Requests\StoreOpeningBalanceBatchRequest;
use App\Models\Customer;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceEntry;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\OpeningBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Opening balances screen — an on-screen ledger for entering each
 * customer's starting position from paper records, then submit → approve
 * (see App\Services\OpeningBalanceService for what approval writes).
 */
class OpeningBalanceController extends Controller
{
    public function __construct(
        private readonly OpeningBalanceService $openingBalances,
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    /**
     * Shows the batch in progress (editable ledger, or read-only while it
     * awaits approval), or — with ?batch={uuid} — an approved batch for
     * reference, plus the history of approved batches.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OpeningBalanceBatch::class);

        $viewing = $request->query('batch')
            ? OpeningBalanceBatch::query()->where('uuid', $request->query('batch'))->where('status', 'approved')->first()
            : null;
        $batch = $viewing ?? $this->openingBalances->openBatch();

        return Inertia::render('OpeningBalances/Index', [
            'batch' => $batch ? $this->shapeBatch($batch, $request) : null,
            'rows' => $batch ? $this->rows($batch) : [],
            'history' => $this->openingBalances->approvedBatches()->map(fn (OpeningBalanceBatch $approved): array => [
                'uuid' => $approved->uuid,
                'go_live_period' => $approved->go_live_period,
                'entries_count' => $approved->entries_count,
                'submitted_by_name' => $approved->submittedBy?->name,
                'approved_by_name' => $approved->approvedBy?->name,
                'approved_at' => $approved->approved_at?->toIso8601String(),
                'is_locked' => $this->openingBalances->isLocked($approved),
            ])->values()->all(),
            'can_start' => $batch === null && $request->user()->can('create', OpeningBalanceBatch::class),
            'month_options' => $this->monthOptions(),
            'default_go_live_period' => Carbon::now()->addMonthNoOverflow()->format('Y-m'),
            'limits' => [
                'max_amount' => OpeningBalanceService::MAX_AMOUNT,
                'max_advance_months' => OpeningBalanceService::MAX_ADVANCE_MONTHS,
            ],
        ]);
    }

    public function store(StoreOpeningBalanceBatchRequest $request): RedirectResponse
    {
        $this->openingBalances->start($request->validated('go_live_period'), $request->user());

        return redirect()->route('opening-balances.index')->with('success', 'Opening balances started. Enter each customer\'s balance from the paper records — your work saves automatically.');
    }

    public function update(StoreOpeningBalanceBatchRequest $request, OpeningBalanceBatch $openingBalanceBatch): RedirectResponse
    {
        $this->openingBalances->changeGoLivePeriod($openingBalanceBatch, $request->validated('go_live_period'));

        return back()->with('success', 'First billing month updated.');
    }

    /**
     * The ledger's autosave — JSON, not an Inertia visit, so typing is never
     * interrupted by a page reload.
     */
    public function saveEntries(SaveOpeningBalanceEntriesRequest $request, OpeningBalanceBatch $openingBalanceBatch): JsonResponse
    {
        $result = $this->openingBalances->saveEntries($openingBalanceBatch, $request->rows());

        return response()->json([...$result, 'saved_at' => Carbon::now()->toIso8601String()]);
    }

    public function submit(Request $request, OpeningBalanceBatch $openingBalanceBatch): RedirectResponse
    {
        $this->authorize('update', $openingBalanceBatch);

        $this->openingBalances->submit($openingBalanceBatch, $request->user());

        return back()->with('success', 'Submitted for approval. The approver has been notified.');
    }

    public function approve(Request $request, OpeningBalanceBatch $openingBalanceBatch): RedirectResponse
    {
        $this->authorize('approve', $openingBalanceBatch);

        $batch = $this->openingBalances->approve($openingBalanceBatch, $request->user());

        return redirect()
            ->route('opening-balances.index', ['batch' => $batch->uuid])
            ->with('success', 'Opening balances approved. They are now each customer\'s starting position for the first bill run.');
    }

    public function reject(RejectOpeningBalanceBatchRequest $request, OpeningBalanceBatch $openingBalanceBatch): RedirectResponse
    {
        $this->openingBalances->reject($openingBalanceBatch, $request->user(), $request->validated('rejection_reason'));

        return back()->with('success', 'Sent back for correction.');
    }

    public function reopen(Request $request, OpeningBalanceBatch $openingBalanceBatch): RedirectResponse
    {
        $this->authorize('reopen', $openingBalanceBatch);

        $this->openingBalances->reopen($openingBalanceBatch);

        return redirect()->route('opening-balances.index')->with('success', 'Reopened for editing. It needs approval again before the first bill run.');
    }

    public function destroy(OpeningBalanceBatch $openingBalanceBatch): RedirectResponse
    {
        $this->authorize('update', $openingBalanceBatch);

        $this->openingBalances->discard($openingBalanceBatch);

        return redirect()->route('opening-balances.index')->with('success', 'Draft discarded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeBatch(OpeningBalanceBatch $batch, Request $request): array
    {
        $batch->loadMissing(['createdBy', 'submittedBy', 'approvedBy', 'rejectedBy']);
        $user = $request->user();

        return [
            'uuid' => $batch->uuid,
            'status' => $batch->status,
            'go_live_period' => $batch->go_live_period,
            'baseline_period' => $batch->baselinePeriod(),
            'created_by_name' => $batch->createdBy?->name,
            'submitted_by_name' => $batch->submittedBy?->name,
            'submitted_at' => $batch->submitted_at?->toIso8601String(),
            'approved_by_name' => $batch->approvedBy?->name,
            'approved_at' => $batch->approved_at?->toIso8601String(),
            'rejected_by_name' => $batch->rejectedBy?->name,
            'rejected_at' => $batch->rejected_at?->toIso8601String(),
            'rejection_reason' => $batch->rejection_reason,
            'updated_at' => $batch->updated_at?->toIso8601String(),
            'is_locked' => $this->openingBalances->isLocked($batch),
            'is_own_submission' => $batch->submitted_by === $user->id,
            'can' => [
                'edit' => $user->can('update', $batch),
                'approve' => $user->can('approve', $batch),
                'reject' => $user->can('reject', $batch),
                'reopen' => $user->can('reopen', $batch),
            ],
        ];
    }

    /**
     * One ledger row per customer. While a batch is being prepared or
     * reviewed: every customer still without billing history (entered or
     * not). For an approved batch: just the customers it covered.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(OpeningBalanceBatch $batch): array
    {
        $entries = $batch->entries()->get()->keyBy('customer_id');

        $customers = $batch->isApproved()
            ? Customer::query()->with('zone')->whereIn('id', $entries->keys())->get()
                ->sortBy(fn (Customer $customer): string => ($customer->zone?->name ?? '').'|'.$customer->name)
                ->values()
            : $this->customers->withoutManuscripts();

        return $customers->map(fn (Customer $customer): array => [
            'uuid' => $customer->uuid,
            'name' => $customer->name,
            'zone_name' => $customer->zone?->name,
            'status' => $customer->status,
            'bill' => (string) $customer->bill,
            'imported_balance' => (string) $customer->others,
            'entry' => $this->shapeEntry($entries->get($customer->id)),
        ])->values()->all();
    }

    /**
     * @return array{arrears: string, advance_amount: string, advance_months: int, free_months: int, note: ?string}|null
     */
    private function shapeEntry(?OpeningBalanceEntry $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'arrears' => (string) $entry->arrears,
            'advance_amount' => (string) $entry->advance_amount,
            'advance_months' => (int) $entry->advance_months,
            'free_months' => (int) $entry->free_months,
            'note' => $entry->note,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function monthOptions(): array
    {
        return Collection::times(14, fn (int $i): Carbon => Carbon::now()->startOfMonth()->subMonthNoOverflow()->addMonthsNoOverflow($i - 1))
            ->map(fn (Carbon $month): array => ['value' => $month->format('Y-m'), 'label' => $month->format('F Y')])
            ->values()
            ->all();
    }
}
