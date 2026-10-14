<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use App\Services\ReceiptEmailService;
use App\Support\PaymentReceiptLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentReceipt
 *
 * JSON shape for the mobile receipt view (Wave 2 of
 * docs/plans/payment-receipts-and-whatsapp.md). Mirrors PaymentResource /
 * ArrearsAdjustmentResource conventions.
 *
 * Two URLs, deliberately:
 *  - `pdf_url`  — the Sanctum-token API download endpoint
 *    (GET /api/v1/payment-receipts/{uuid}/pdf).
 *  - `shared_pdf_url` — the signed, public, ~7-day link the app opens in the
 *    device browser (React Native has no PDF viewer / file-system libs in
 *    this project) and that Wave 3 drops into a WhatsApp message.
 */
class PaymentReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $emails = app(ReceiptEmailService::class);

        return [
            'uuid' => $this->uuid,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'amount' => (string) $this->amount,
            'payment_uuid' => $this->whenLoaded('payment', fn () => $this->payment?->uuid),
            'pdf_url' => route('api.payment-receipts.pdf', ['receipt' => $this->uuid]),
            'shared_pdf_url' => PaymentReceiptLink::shared($this->resource),
            // One signed link per paper size, for pocket thermal printers.
            'shared_pdf_urls' => [
                PaymentReceiptService::FORMAT_A6 => PaymentReceiptLink::shared($this->resource),
                PaymentReceiptService::FORMAT_THERMAL_58 => PaymentReceiptLink::shared($this->resource, PaymentReceiptService::FORMAT_THERMAL_58),
                PaymentReceiptService::FORMAT_THERMAL_80 => PaymentReceiptLink::shared($this->resource, PaymentReceiptService::FORMAT_THERMAL_80),
            ],
            'invoice_number' => $this->snapshot['invoice']['number'] ?? null,
            'customer_email' => $emails->recipientEmail($this->resource),
            // Why "Email receipt" can't be used right now, or null.
            'email_unavailable_reason' => $emails->unavailableReason($this->resource),
        ];
    }
}
