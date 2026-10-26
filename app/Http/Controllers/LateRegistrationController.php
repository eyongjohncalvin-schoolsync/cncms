<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\LateRegistrationService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Charge as old debt" for a customer registered late (owner, 2026-10-26):
 * the months that passed unbilled — or every month since connection, ending
 * their free months — become an arrears adjustment on the current bill,
 * approved by someone else (App\Services\LateRegistrationService). Decided
 * by those who approve arrears decisions (`arrears.approve`).
 */
class LateRegistrationController extends Controller
{
    public function __construct(
        private readonly LateRegistrationService $lateRegistrations,
        private readonly TenantContext $context,
    ) {}

    public function charge(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($this->context->can('arrears.approve'), 403);

        $validated = $request->validate([
            'scope' => ['required', 'in:missed,all'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->lateRegistrations->charge($customer, $validated['scope'], (string) $validated['amount'], $validated['note'], $request->user());

        return back()->with('success', 'Old debt of '.number_format((float) $validated['amount'], 0, '.', ',').' FCFA asked for — another approver confirms it on Arrears Adjustments'
            .($validated['scope'] === 'all' ? '; the free months end when it is approved.' : '.'));
    }
}
