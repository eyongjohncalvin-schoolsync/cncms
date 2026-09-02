<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentReceiptResource;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON API counterpart of the web PaymentReceiptController (Wave 2 of
 * docs/plans/payment-receipts-and-whatsapp.md). Read-only: mobile views and
 * shares a receipt, it never issues one (matching the app-wide "mobile
 * creates payments, web verifies / issues receipts" split).
 *
 * Both endpoints are Sanctum-token + ResolveTenant gated (routes/api.php's
 * group). `show` binds by the PAYMENT uuid — the mobile client only ever
 * knows a payment's uuid (its local outbox row's `server_uuid`), never the
 * receipt's — matching how Api\PaymentController addresses everything.
 */
class PaymentReceiptController extends Controller
{
    public function __construct(
        private readonly PaymentReceiptService $receipts,
    ) {}

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        $receipt = $payment->receipt;

        abort_if($receipt === null, 404, 'No receipt has been issued for this payment.');

        $receipt->setRelation('payment', $payment);

        return (new PaymentReceiptResource($receipt))->response();
    }

    public function downloadPdf(PaymentReceipt $receipt): StreamedResponse
    {
        $this->authorize('view', $receipt);

        $path = $this->receipts->pdf($receipt);

        return Storage::disk($receipt->pdf_disk)->download(
            $path,
            "receipt-{$receipt->receipt_number}.pdf",
        );
    }
}
