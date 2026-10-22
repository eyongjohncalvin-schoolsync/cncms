<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RejectInstallationWaiverRequest;
use App\Http\Requests\RequestInstallationWaiverRequest;
use App\Http\Requests\StoreCustomerChargeRequest;
use App\Http\Requests\StoreInstallationPaymentRequest;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Services\CustomerChargeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The installation-fee actions on the customer page
 * (docs/plans/installation-and-promotions.md, phase 1): open a fee, record an
 * instalment, ask for a waiver, approve / reject it. Phase 2 adds the full
 * "Installation & first payment" section of the add-customer form; these
 * stay as the after-the-fact actions. See App\Services\CustomerChargeService.
 */
class CustomerChargeController extends Controller
{
    public function __construct(
        private readonly CustomerChargeService $charges,
    ) {}

    public function store(StoreCustomerChargeRequest $request, Customer $customer): RedirectResponse
    {
        $this->charges->open(
            $customer,
            number_format((float) $request->validated('amount'), 2, '.', ''),
            $request->validated('reason'),
            $request->user(),
        );

        return back()->with('success', 'Installation fee added. It is shown on the bill but never added to the monthly amount due.');
    }

    public function storePayment(StoreInstallationPaymentRequest $request, CustomerCharge $customerCharge): RedirectResponse
    {
        $payment = $this->charges->recordPayment(
            $customerCharge,
            number_format((float) $request->validated('amount'), 2, '.', ''),
            $request->validated('method'),
            $request->validated('method_reference'),
        );

        return back()->with('success', $payment->verification_status === 'verified'
            ? 'Installation payment recorded.'
            : 'Installation payment recorded — it counts once it is verified.');
    }

    public function requestWaiver(RequestInstallationWaiverRequest $request, CustomerCharge $customerCharge): RedirectResponse
    {
        $this->charges->requestWaiver($customerCharge, $request->validated('reason'), $request->user());

        return back()->with('success', 'Waiver requested. Someone else must approve it.');
    }

    public function approveWaiver(Request $request, CustomerCharge $customerCharge): RedirectResponse
    {
        $this->authorize('approveWaiver', $customerCharge);

        $this->charges->approveWaiver($customerCharge, $request->user());

        return back()->with('success', 'Waiver approved — the rest of the installation fee is waived.');
    }

    public function rejectWaiver(RejectInstallationWaiverRequest $request, CustomerCharge $customerCharge): RedirectResponse
    {
        $this->charges->rejectWaiver($customerCharge, $request->user(), $request->validated('reason'));

        return back()->with('success', 'Waiver rejected.');
    }
}
