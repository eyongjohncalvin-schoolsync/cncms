<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ActivateFieldInstallationRequest;
use App\Http\Requests\RejectFieldInstallationRequest;
use App\Models\FieldInstallation;
use App\Services\FieldInstallationService;
use Illuminate\Http\RedirectResponse;

/**
 * The office's side of offline field installations
 * (docs/plans/installation-and-promotions.md, phase 3): activate or reject
 * a registration from the customer page. The list is the customers page's
 * "Pending activation" view; the dashboard shows the count.
 */
class FieldInstallationController extends Controller
{
    public function __construct(
        private readonly FieldInstallationService $fieldInstallations,
    ) {}

    public function activate(ActivateFieldInstallationRequest $request, FieldInstallation $fieldInstallation): RedirectResponse
    {
        $activated = $this->fieldInstallations->activate($fieldInstallation, $request->validated(), $request->user());

        return back()->with('success', "{$activated->customer->name} is activated — billing starts {$activated->customer->billing_starts_period}.");
    }

    public function reject(RejectFieldInstallationRequest $request, FieldInstallation $fieldInstallation): RedirectResponse
    {
        $rejected = $this->fieldInstallations->reject($fieldInstallation, $request->validated('reason'), $request->user());

        return redirect()->route('customers.index', ['pending_activation' => 1])
            ->with('success', "{$rejected->customer->name}'s registration was rejected and archived".($rejected->refund_voucher_id !== null ? ' — a refund bon is waiting for approval.' : '.'));
    }
}
