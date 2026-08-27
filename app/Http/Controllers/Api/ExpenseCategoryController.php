<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Http\Requests\UpdateExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Services\ExpenseCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly ExpenseCategoryService $categories,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        $categories = $this->categories->list($request->boolean('active_only'));

        return ExpenseCategoryResource::collection($categories)->response();
    }

    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->validated());

        return (new ExpenseCategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $category): JsonResponse
    {
        $category = $this->categories->update($category, $request->validated());

        return (new ExpenseCategoryResource($category))->response();
    }

    /**
     * Deactivate — api-spec.md section 6.6 explicitly calls out "not hard delete".
     */
    public function destroy(ExpenseCategory $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $category = $this->categories->deactivate($category);

        return (new ExpenseCategoryResource($category))->additional(['message' => 'Category deactivated'])->response();
    }
}
