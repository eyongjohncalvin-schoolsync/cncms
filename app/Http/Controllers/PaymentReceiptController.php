<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Tenant;
use App\Services\PaymentReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * View / download actions for the business-issued payment receipt (Wave 2 of
 * docs/plans/payment-receipts-and-whatsapp.md). The receipt DATA itself
 * rides on PaymentController::show()'s Inertia payload (`receipt` prop) — this
 * controller only owns the PDF download, the manual issue/re-issue action,
 * and the signed public PDF link.
 *
 * PaymentReceiptService is the only writer/renderer — this controller never
 * touches `snapshot` / `pdf_path` directly (see that service's class doc).
 */
class PaymentReceiptController extends Controller
{
    public function __construct(
        private readonly PaymentReceiptService $receipts,
    ) {}

    /**
     * Streams the receipt PDF to an authenticated staff member. Renders +
     * caches it on first call via PaymentReceiptService::pdf().
     */
    public function downloadPdf(PaymentReceipt $receipt): StreamedResponse
    {
        $this->authorize('view', $receipt);

        $path = $this->receipts->pdf($receipt);

        return Storage::disk($receipt->pdf_disk)->download(
            $path,
            "receipt-{$receipt->receipt_number}.pdf",
        );
    }

    /**
     * Manual "Issue / re-issue receipt" — for a payment recorded before
     * receipts shipped, or a correction. Gated on `payments.issue_receipt`.
     * A receipt is only ever a record of a VERIFIED payment, so this refuses
     * anything else rather than minting a receipt for a pending/rejected row.
     */
    public function issue(Payment $payment): RedirectResponse
    {
        $this->authorize('issue', PaymentReceipt::class);

        if ($payment->verification_status !== 'verified') {
            return back()->with('error', 'A receipt can only be issued for a verified payment.');
        }

        $this->receipts->issueFor($payment, request()->user());

        return back()->with('success', 'Receipt issued.');
    }

    /**
     * Signed, public, UNAUTHENTICATED receipt PDF — the WhatsApp-shareable
     * link (Wave 3 builds the message; the route is here now so Wave 3 just
     * references App\Support\PaymentReceiptLink::shared()). Also what the
     * mobile app opens in the device browser.
     *
     * This route is outside ['auth', 'tenant.web'], so tenancy is NOT yet
     * initialised and there is no user. The tenant key travels in the signed
     * URL (`?tenant=…`, covered by the signature — see PaymentReceiptLink) and
     * is initialised here before the receipt is looked up. The receipt is
     * bound by a plain string uuid, NOT route-model binding, precisely
     * because binding would query the central connection before this runs.
     */
    public function sharedPdf(Request $request, string $receiptUuid): StreamedResponse
    {
        $tenantKey = (string) $request->query('tenant', '');
        abort_if($tenantKey === '', 404);

        $tenant = Tenant::find($tenantKey);
        abort_if($tenant === null, 404);

        tenancy()->initialize($tenant);

        $receipt = PaymentReceipt::query()->where('uuid', $receiptUuid)->first();

        // 404 (not 403) for a missing OR voided receipt — a void receipt's
        // public link must simply stop working, without confirming the
        // receipt ever existed to an unauthenticated caller.
        abort_if($receipt === null || $receipt->isVoid(), 404);

        $path = $this->receipts->pdf($receipt);

        return Storage::disk($receipt->pdf_disk)->download(
            $path,
            "receipt-{$receipt->receipt_number}.pdf",
        );
    }
}
