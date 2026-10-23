<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBudgetsRequest;
use App\Models\Expenditure;
use App\Services\BudgetService;
use App\Support\BusinessTimezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resources → Budgets (E5 of docs/plans/expenditures-and-spending-controls.md):
 * a categories × month grid, copy from last month, total against the
 * monthly cap. `budgets.manage` (owner, admin, manager). Warnings only.
 */
class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('manageBudgets', Expenditure::class);

        $month = (string) $request->query('month', '');
        $month = BusinessTimezone::isValidPeriod($month) ? $month : BusinessTimezone::currentPeriod();

        return Inertia::render('Resources/Budgets', [
            'grid' => $this->budgets->grid($month),
            'current_month' => BusinessTimezone::currentPeriod(),
        ]);
    }

    public function update(UpdateBudgetsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->budgets->save($validated['month'], $validated['amounts']);

        return redirect()->route('resources.budgets.index', ['month' => $validated['month']])->with('success', 'Budgets saved.');
    }

    public function copyFromPreviousMonth(Request $request): RedirectResponse
    {
        $this->authorize('manageBudgets', Expenditure::class);

        $validated = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $copied = $this->budgets->copyFromPrevious($validated['month']);

        return redirect()->route('resources.budgets.index', ['month' => $validated['month']])
            ->with('success', $copied === 0 ? 'Nothing to copy — every category already has a budget, or last month had none.' : "{$copied} budget(s) copied from last month.");
    }
}
