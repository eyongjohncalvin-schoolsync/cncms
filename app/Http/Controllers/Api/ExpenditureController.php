<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateExpenditureRequest;
use App\Http\Requests\VoidExpenditureRequest;
use App\Http\Resources\ExpenditureResource;
use App\Models\Expenditure;
use App\Services\ExpenditureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expenditures over the API (docs/plans/expenditures-and-spending-controls.md).
 * There is no store: every cash-out is a bon (E1) — the mobile app requests
 * one through sync. No destroy either: a legacy row is voided with a reason.
 */
class ExpenditureController extends Controller
{
    public function __construct(
        private readonly ExpenditureService $expenditures,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expenditure::class);

        $perPage = (int) $request->integer('per_page', 25);

        $filters = $request->only(['month', 'category_uuid', 'from', 'to', 'user_uuid', 'source', 'status']);

        return ExpenditureResource::collection($this->expenditures->list($filters, $perPage))->response();
    }

    public function update(UpdateExpenditureRequest $request, Expenditure $expenditure): JsonResponse
    {
        $validated = $request->validated();

        $expenditure = $this->expenditures->edit($expenditure, collect($validated)->except('reason')->all(), $validated['reason']);

        return (new ExpenditureResource($expenditure))->response();
    }

    public function void(VoidExpenditureRequest $request, Expenditure $expenditure): JsonResponse
    {
        $expenditure = $this->expenditures->void($expenditure, $request->user(), $request->validated('reason'));

        return (new ExpenditureResource($expenditure->load(['category', 'user'])))->response();
    }
}
