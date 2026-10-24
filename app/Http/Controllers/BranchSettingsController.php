<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBranchSettingsRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A branch's own contact details, MoMo number and fees (branches v2 §4.6,
 * D4): what its bills, receipts and messages print, and the fees its staff
 * charge. Empty fields inherit headquarters' company settings. Managed by
 * whoever manages branches, or by the branch's own staff
 * (BranchPolicy::manageSettings()).
 */
class BranchSettingsController extends Controller
{
    /** "My branch" for a branch's own staff. */
    public function mine(TenantContext $context): RedirectResponse
    {
        abort_unless($context->isFenced(), 404);

        $branch = Branch::query()->findOrFail($context->branchId);

        return redirect()->route('branches.settings.edit', $branch);
    }

    public function edit(Branch $branch): Response
    {
        $this->authorize('manageSettings', $branch);

        $headquarters = Company::cached();

        return Inertia::render('Branches/Settings', [
            'branch' => [
                'uuid' => $branch->uuid,
                'name' => $branch->name,
                'code' => $branch->code,
                ...collect(Branch::OVERRIDABLE)->mapWithKeys(fn (string $field): array => [$field => $branch->{$field} !== null ? (string) $branch->{$field} : null])->all(),
            ],
            // Shown as each field's placeholder: what the branch uses when the field is empty.
            'headquarters' => collect(Branch::OVERRIDABLE)
                ->mapWithKeys(fn (string $field): array => [$field => $headquarters?->{$field} !== null ? (string) $headquarters->{$field} : null])
                ->all(),
            'can_manage_branches' => request()->user()->can('update', $branch),
        ]);
    }

    public function update(UpdateBranchSettingsRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        return redirect()->route('branches.settings.edit', $branch)->with('success', "{$branch->name} settings saved.");
    }
}
