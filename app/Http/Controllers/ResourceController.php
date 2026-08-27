<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\ExpenditureData;
use App\Http\Requests\StoreExpenditureRequest;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Http\Requests\UpdateExpenditureRequest;
use App\Http\Requests\UpdateExpenseCategoryRequest;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Services\ExpenditureService;
use App\Services\ExpenseCategoryService;
use App\Services\ResourcesDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web (session-auth, Inertia) counterpart of Api\ExpenditureController,
 * Api\ExpenseCategoryController and Api\ResourcesDashboardController — same
 * ExpenditureService/ExpenseCategoryService/ResourcesDashboardService,
 * same FormRequests/Policies, just rendering Inertia pages and
 * redirecting-with-flash instead of returning JSON. See PaymentController's
 * class doc for why web controllers never call the JSON /api/v1/*
 * endpoints. Kept as a single controller (rather than split like the Api
 * namespace) per this module's task spec — dashboard + expenditures +
 * categories are one cohesive "Resources" admin area from the web panel's
 * point of view.
 */
class ResourceController extends Controller
{
    public function __construct(
        private readonly ExpenditureService $expenditures,
        private readonly ExpenseCategoryService $categories,
        private readonly ResourcesDashboardService $dashboard,
    ) {}

    public function dashboard(Request $request): Response
    {
        $this->authorize('viewDashboard', Expenditure::class);

        $period = $request->query('period');

        $data = $this->dashboard->dashboard($period !== null ? (string) $period : null);

        return Inertia::render('Resources/Dashboard', $data);
    }

    public function expenditures(Request $request): Response
    {
        $this->authorize('viewAny', Expenditure::class);

        $perPage = (int) $request->integer('per_page', 20);

        $filters = $request->only(['category_uuid', 'from', 'to']);

        $expenditures = $this->expenditures->list($filters, $perPage);

        $raw = $expenditures->toArray();

        return Inertia::render('Resources/Expenditures/Index', [
            'expenditures' => [
                'data' => $expenditures->getCollection()
                    ->map(fn (Expenditure $expenditure): array => $this->formatExpenditure($expenditure))
                    ->values()
                    ->all(),
                'links' => $raw['links'],
                'meta' => [
                    'current_page' => $raw['current_page'],
                    'per_page' => $raw['per_page'],
                    'total' => $raw['total'],
                    'last_page' => $raw['last_page'],
                ],
            ],
            'filters' => [
                'category_uuid' => $filters['category_uuid'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'categories' => $this->activeCategoryOptions(),
        ]);
    }

    public function createExpenditure(): Response
    {
        $this->authorize('create', Expenditure::class);

        return Inertia::render('Resources/Expenditures/Create', [
            'categories' => $this->activeCategoryOptions(),
        ]);
    }

    public function storeExpenditure(StoreExpenditureRequest $request): RedirectResponse
    {
        $this->expenditures->create(ExpenditureData::fromArray($request->validated()), $request->user()->id);

        return redirect()->route('resources.expenditures.index')->with('success', 'Expenditure recorded successfully.');
    }

    public function updateExpenditure(UpdateExpenditureRequest $request, Expenditure $expenditure): RedirectResponse
    {
        $this->expenditures->update($expenditure, ExpenditureData::fromArray($request->validated()));

        return back()->with('success', 'Expenditure updated.');
    }

    public function destroyExpenditure(Expenditure $expenditure): RedirectResponse
    {
        $this->authorize('delete', $expenditure);

        $this->expenditures->delete($expenditure);

        return redirect()->route('resources.expenditures.index')->with('success', 'Expenditure deleted.');
    }

    public function categories(): Response
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        $categories = $this->categories->list();

        return Inertia::render('Resources/Categories', [
            'categories' => $categories->map(fn (ExpenseCategory $category): array => [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'icon' => $category->icon,
                'is_active' => $category->is_active,
                'sort_order' => $category->sort_order,
            ])->values(),
        ]);
    }

    public function storeCategory(StoreExpenseCategoryRequest $request): RedirectResponse
    {
        $this->categories->create($request->validated());

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(UpdateExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        $this->categories->update($category, $request->validated());

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(ExpenseCategory $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $this->categories->deactivate($category);

        return back()->with('success', 'Category deactivated.');
    }

    /**
     * @return array<int, array{uuid: string, name: string}>
     */
    private function activeCategoryOptions(): array
    {
        return $this->categories->list(true)
            ->map(fn (ExpenseCategory $category): array => ['uuid' => $category->uuid, 'name' => $category->name])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatExpenditure(Expenditure $expenditure): array
    {
        return [
            'uuid' => $expenditure->uuid,
            'category_uuid' => $expenditure->category->uuid,
            'category_name' => $expenditure->category->name,
            'amount' => (string) $expenditure->amount,
            'description' => $expenditure->description,
            'spent_at' => $expenditure->spent_at?->toDateString(),
            'notes' => $expenditure->notes,
            'recorded_offline' => $expenditure->recorded_offline,
            'recorded_by_name' => $expenditure->user->name,
            'created_at' => $expenditure->created_at?->toIso8601String(),
        ];
    }
}
