<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReinstallCustomerRequest;
use App\Http\Requests\RejectInstallationFeeRequest;
use App\Http\Requests\RejectInstallationRefundRequest;
use App\Http\Requests\RejectInstallationWaiverRequest;
use App\Http\Requests\RelocateCustomerRequest;
use App\Http\Requests\RequestInstallationRefundRequest;
use App\Http\Requests\RequestInstallationWaiverRequest;
use App\Http\Requests\StoreCustomerChargeRequest;
use App\Http\Requests\StoreInstallationPaymentRequest;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Services\CustomerChargeService;
use App\Services\CustomerRelocationService;
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
        private readonly CustomerRelocationService $relocations,
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

    /**
     * "Relocate": the customer moved house — a relocation fee, the payment
     * taken now (with receipt) and the new address/zone, in one go.
     */
    public function relocate(RelocateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $result = $this->relocations->relocate($customer, $request->toData(), $request->user());

        return back()->with('success', $this->oneOffMessage($result['charge'], 'Relocation recorded — the new address is saved.'));
    }

    /** "Re-install": installed again at the same address — a re-installation fee. */
    public function reinstall(ReinstallCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $result = $this->relocations->reinstall($customer, $request->toData(), $request->user());

        return back()->with('success', $this->oneOffMessage($result['charge'], 'Re-installation recorded.'));
    }

    private function oneOffMessage(CustomerCharge $charge, string $done): string
    {
        return match (true) {
            $charge->hasPendingWaiver() => "{$done} The waiver waits for someone else to approve it.",
            $charge->hasPendingFeeApproval() => "{$done} The lower fee waits for someone else to approve it.",
            default => "{$done} The fee is shown on the bill but never added to the monthly amount due.",
        };
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

    /** A fee below the offer's (phase 2, D8). */
    public function approveFee(Request $request, CustomerCharge $customerCharge): RedirectResponse
    {
        $this->authorize('approveFee', $customerCharge);

        $this->charges->approveFee($customerCharge, $request->user());

        return back()->with('success', 'Installation fee approved.');
    }

    public function rejectFee(RejectInstallationFeeRequest $request, CustomerCharge $customerCharge): RedirectResponse
    {
        $this->charges->rejectFee($customerCharge, $request->user(), $request->validated('reason'));

        return back()->with('success', 'Lower fee rejected — the offer\'s fee applies.');
    }

    /** "Refund installation" (D9). */
    public function requestRefund(RequestInstallationRefundRequest $request, CustomerCharge $customerCharge): RedirectResponse
    {
        $this->charges->requestRefund(
            $customerCharge,
            number_format((float) $request->validated('amount'), 2, '.', ''),
            $request->validated('reason'),
            $request->user(),
        );

        return back()->with('success', 'Refund requested. Someone else must approve it.');
    }

    public function approveRefund(Request $request, CustomerCharge $customerCharge): RedirectResponse
    {
        $this->authorize('approveRefund', $customerCharge);

        $charge = $this->charges->approveRefund($customerCharge, $request->user());

        return back()->with('success', "Refund approved — bon {$charge->refundVoucher?->number} pays it out. The installation's unused free months are forfeited.");
    }

    public function rejectRefund(RejectInstallationRefundRequest $request, CustomerCharge $customerCharge): RedirectResponse
    {
        $this->charges->rejectRefund($customerCharge, $request->user(), $request->validated('reason'));

        return back()->with('success', 'Refund rejected.');
    }
}
