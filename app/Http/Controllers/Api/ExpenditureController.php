<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\ExpenditureData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenditureDecisionRequest;
use App\Http\Requests\StoreExpenditureRequest;
use App\Http\Requests\UpdateExpenditureRequest;
use App\Http\Resources\ExpenditureResource;
use App\Models\Expenditure;
use App\Services\ExpenditureService;
use App\Services\ExpenseControlService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses for the mobile app: list, record (with the receipt photo),
 * edit while pending, add a receipt, approve / reject / void, the
 * approvals inbox and the private receipt photo. Expenses are never
 * deleted — DELETE voids (with a reason).
 */
class ExpenditureController extends Controller
{
    public function __construct(
        private readonly ExpenditureService $expenditures,
        private readonly TenantContext $context,
        private readonly ExpenseControlService $controls,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expenditure::class);

        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));

        $filters = $request->only(['category_uuid', 'from', 'to', 'user_uuid', 'status', 'supplier_uuid', 'service_uuid']);

        // ?mine=1 — the caller's own expenses (the app's history screen).
        if ($request->boolean('mine')) {
            $filters['user_uuid'] = $request->user()->uuid;
        }

        $expenditures = $this->expenditures->list($filters, $perPage);

        return ExpenditureResource::collection($expenditures)->response();
    }

    public function show(Expenditure $expenditure): JsonResponse
    {
        $this->authorize('view', $expenditure);

        return (new ExpenditureResource($expenditure->load(['category', 'user', 'supplier', 'service', 'firstApprover', 'approver', 'rejecter', 'voider'])))->response();
    }

    public function store(StoreExpenditureRequest $request): JsonResponse
    {
        $expenditure = $this->expenditures->create(
            ExpenditureData::fromArray($request->validated()),
            $request->user()->id,
            $request->file('receipt'),
            $request->receiptMeta(),
        );

        return (new ExpenditureResource($expenditure))->response()->setStatusCode(201);
    }

    public function update(UpdateExpenditureRequest $request, Expenditure $expenditure): JsonResponse
    {
        $expenditure = $this->expenditures->update($expenditure, ExpenditureData::fromArray($request->validated()));

        return (new ExpenditureResource($expenditure))->response();
    }

    public function attachReceipt(Request $request, Expenditure $expenditure): JsonResponse
    {
        $this->authorize('attachReceipt', $expenditure);

        $validated = $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:6144'],
            'captured_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'source' => ['nullable', 'string', 'in:camera,gallery'],
        ]);

        $expenditure = $this->expenditures->attachReceipt($expenditure, $request->file('receipt'), $validated);

        return (new ExpenditureResource($expenditure->load(['category', 'user'])))->response();
    }

    public function receipt(Expenditure $expenditure): StreamedResponse
    {
        $this->authorize('view', $expenditure);

        return $this->expenditures->receiptResponse($expenditure);
    }

    /**
     * GET /resources/expense-limits — the approval and receipt limits, so
     * the app can say "a receipt photo is needed" before saving.
     */
    public function limits(): JsonResponse
    {
        $this->authorize('viewAny', Expenditure::class);

        return response()->json(['data' => $this->controls->limits()]);
    }

    /**
     * GET /resources/approvals — what this person can decide on now.
     */
    public function approvals(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expenditure::class);

        $items = $this->expenditures->awaitingDecisionBy(
            $request->user(),
            $this->context->can('expenditures.approve'),
            $this->context->can('expenditures.approve_large'),
        );

        return ExpenditureResource::collection($items)->response();
    }

    public function approve(Request $request, Expenditure $expenditure): JsonResponse
    {
        $this->authorize('approve', $expenditure);

        $this->expenditures->approve($expenditure, $request->user());

        return (new ExpenditureResource($expenditure->load(['category', 'user', 'firstApprover', 'approver'])))->response();
    }

    public function reject(ExpenditureDecisionRequest $request, Expenditure $expenditure): JsonResponse
    {
        $this->authorize('reject', $expenditure);

        $this->expenditures->reject($expenditure, $request->user(), $request->validated('reason'));

        return (new ExpenditureResource($expenditure->load(['category', 'user', 'rejecter'])))->response();
    }

    public function void(ExpenditureDecisionRequest $request, Expenditure $expenditure): JsonResponse
    {
        $this->authorize('void', $expenditure);

        $this->expenditures->void($expenditure, $request->user(), $request->validated('reason'));

        return (new ExpenditureResource($expenditure->load(['category', 'user', 'voider'])))->response();
    }

    /**
     * Older app builds call DELETE; it voids now, never deletes.
     */
    public function destroy(Request $request, Expenditure $expenditure): JsonResponse
    {
        $this->authorize('void', $expenditure);

        $reason = trim((string) $request->input('reason', '')) ?: 'Withdrawn from the mobile app';

        $this->expenditures->void($expenditure, $request->user(), $reason);

        return response()->json(['message' => 'Expenditure voided']);
    }
}
