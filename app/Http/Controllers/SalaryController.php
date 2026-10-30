<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SetStaffSalaryRequest;
use App\Http\Requests\StoreSalaryGradeRequest;
use App\Models\Agent;
use App\Models\SalaryGrade;
use App\Models\Scopes\BranchScope;
use App\Models\StaffSalary;
use App\Models\TenantUser;
use App\Services\SalaryService;
use App\Services\VoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Salaries (branches v2 §4.5, D8): headquarters' salary grades and the
 * salaries waiting for its approval (Settings → Salaries), and setting one
 * person's salary from the Users Control Center.
 */
class SalaryController extends Controller
{
    public function __construct(
        private readonly SalaryService $salaries,
    ) {}

    public function index(): Response
    {
        Gate::authorize('salaries.manageScale');

        return Inertia::render('Settings/Salaries', [
            'grades' => SalaryGrade::query()->orderBy('min_amount')->get()->map(fn (SalaryGrade $grade): array => [
                'uuid' => $grade->uuid,
                'name' => $grade->name,
                'min_amount' => (string) $grade->min_amount,
                'max_amount' => (string) $grade->max_amount,
                'is_active' => $grade->is_active,
            ])->values()->all(),
            'pending' => $this->salaries->pending()->map(fn (StaffSalary $salary): array => [
                'uuid' => $salary->uuid,
                'person' => $salary->user?->name,
                'branch' => $salary->branch?->name,
                'grade' => $salary->grade?->name,
                'range' => $salary->grade !== null ? [(string) $salary->grade->min_amount, (string) $salary->grade->max_amount] : null,
                'amount' => (string) $salary->amount,
                'reason' => $salary->reason,
                'set_by' => $salary->setBy?->name,
                'requested_at' => $salary->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function storeGrade(StoreSalaryGradeRequest $request): RedirectResponse
    {
        SalaryGrade::query()->create($request->validated());

        return redirect()->route('settings.salaries.index')->with('success', 'Salary grade added.');
    }

    public function updateGrade(StoreSalaryGradeRequest $request, SalaryGrade $salaryGrade): RedirectResponse
    {
        $salaryGrade->update($request->validated());

        return redirect()->route('settings.salaries.index')->with('success', 'Salary grade saved.');
    }

    public function set(SetStaffSalaryRequest $request, TenantUser $tenantUser): RedirectResponse
    {
        $data = $request->validated();
        $grade = isset($data['grade_uuid']) ? SalaryGrade::query()->where('uuid', $data['grade_uuid'])->first() : null;

        $salary = $this->salaries->set((int) $tenantUser->user_id, $this->branchOf($tenantUser), (string) $data['amount'], $grade, $data['reason'] ?? null, $request->user());

        return redirect()->route('users.index')->with('success', $salary->status === StaffSalary::STATUS_APPROVED
            ? 'Salary saved.'
            : 'Salary is outside the grade\'s range — sent to headquarters for approval. The current salary stays until then.');
    }

    /**
     * Raises the bon that pays a person's salary for one month (wave 4): the
     * amount is their approved salary; approved and paid like any bon, never
     * by the person paid. Whoever may set their salary may ask for it.
     */
    public function pay(Request $request, TenantUser $tenantUser): RedirectResponse
    {
        Gate::authorize('salaries.setFor', $tenantUser);

        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            // Empty = what is left of the month; less = a salary advance (owner, 2026-10-30).
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
        ]);

        $amount = isset($validated['amount']) ? number_format((float) $validated['amount'], 2, '.', '') : null;
        $voucher = app(VoucherService::class)->requestSalary($tenantUser->user, $validated['period'], $request->user(), $amount);

        return redirect()->route('vouchers.show', $voucher)->with('success', "{$voucher->number}: salary bon for {$validated['period']} — waiting for approval.");
    }

    public function approve(Request $request, StaffSalary $staffSalary): RedirectResponse
    {
        Gate::authorize('salaries.manageScale');

        $this->salaries->decide($staffSalary, true, $request->string('note')->toString() ?: null, $request->user());

        return redirect()->route('settings.salaries.index')->with('success', 'Salary approved.');
    }

    public function reject(Request $request, StaffSalary $staffSalary): RedirectResponse
    {
        Gate::authorize('salaries.manageScale');

        $this->salaries->decide($staffSalary, false, $request->string('note')->toString() ?: null, $request->user());

        return redirect()->route('settings.salaries.index')->with('success', 'Salary rejected — the current salary stays.');
    }

    /** A person's branch: an agent's zone's, else their fence (null = not fenced). */
    private function branchOf(TenantUser $tenantUser): ?int
    {
        $agentBranchId = Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $tenantUser->user_id)->value('branch_id');

        return $agentBranchId !== null ? (int) $agentBranchId : $tenantUser->branch_id;
    }
}
