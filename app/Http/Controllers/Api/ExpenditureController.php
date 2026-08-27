<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\ExpenditureData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenditureRequest;
use App\Http\Requests\UpdateExpenditureRequest;
use App\Http\Resources\ExpenditureResource;
use App\Models\Expenditure;
use App\Services\ExpenditureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenditureController extends Controller
{
    public function __construct(
        private readonly ExpenditureService $expenditures,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expenditure::class);

        $perPage = (int) $request->integer('per_page', 25);

        $filters = $request->only(['category_uuid', 'from', 'to', 'user_uuid']);

        $expenditures = $this->expenditures->list($filters, $perPage);

        return ExpenditureResource::collection($expenditures)->response();
    }

    public function store(StoreExpenditureRequest $request): JsonResponse
    {
        $expenditure = $this->expenditures->create(
            ExpenditureData::fromArray($request->validated()),
            $request->user()->id,
        );

        return (new ExpenditureResource($expenditure))->response()->setStatusCode(201);
    }

    public function update(UpdateExpenditureRequest $request, Expenditure $expenditure): JsonResponse
    {
        $expenditure = $this->expenditures->update($expenditure, ExpenditureData::fromArray($request->validated()));

        return (new ExpenditureResource($expenditure))->response();
    }

    public function destroy(Expenditure $expenditure): JsonResponse
    {
        $this->authorize('delete', $expenditure);

        $this->expenditures->delete($expenditure);

        return response()->json(['message' => 'Expenditure deleted']);
    }
}
