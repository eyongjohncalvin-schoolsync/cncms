<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentReceiptResource;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use App\Services\ReceiptWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
        private readonly ReceiptWhatsAppService $whatsapp,
    ) {}

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        $receipt = $payment->receipt;

        abort_if($receipt === null, 404, 'No receipt has been issued for this payment.');

        $receipt->setRelation('payment', $payment);

        return (new PaymentReceiptResource($receipt))->response();
    }

    /**
     * GET /api/v1/payments/{payment}/receipt/whatsapp-message — mobile
     * counterpart of the web "Send via WhatsApp" action (Wave 3 of
     * payment-receipts-and-whatsapp.md). Same shape as
     * Api\BillController::whatsappMessage(): returns the two raw ingredients
     * (`phone`, `message`) plus an honest `reason` — the mobile client
     * builds the wa.me link itself (mobile/src/utils/whatsapp.ts).
     *
     * Records the send to `sent_log` when a link is actually deliverable
     * (this is the only "the office reminded them" signal the mobile flow
     * has — there is no separate record endpoint). A voided receipt is
     * refused outright with a 422, matching the web action.
     */
    public function whatsappMessage(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        $receipt = $payment->receipt;

        abort_if($receipt === null, 404, 'No receipt has been issued for this payment.');

        if ($receipt->isVoid()) {
            throw ValidationException::withMessages([
                'receipt' => ['This receipt has been voided and cannot be sent.'],
            ]);
        }

        $phone = $this->whatsapp->recipientPhone($receipt);
        $message = $phone !== null ? $this->whatsapp->composeMessage($receipt) : null;

        if ($phone !== null) {
            $this->whatsapp->recordSent($receipt, ReceiptWhatsAppService::CHANNEL_MANUAL, $request->user(), $phone);
        }

        return response()->json([
            'data' => [
                'has_phone' => $phone !== null,
                'available' => $phone !== null,
                'reason' => $phone === null ? 'no_phone' : null,
                'phone' => $phone,
                'message' => $message,
            ],
        ]);
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
