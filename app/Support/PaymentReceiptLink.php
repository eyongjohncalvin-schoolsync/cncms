<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\URL;

/**
 * Builds the signed, public, unauthenticated URL to a receipt's PDF — the
 * link that goes into a WhatsApp message (Wave 3 of
 * docs/plans/payment-receipts-and-whatsapp.md) and that the mobile app opens
 * in the device browser (Wave 2), neither of which can present a session
 * cookie or a Sanctum token.
 *
 * TENANT RESOLUTION — the route (payment-receipts/{receiptUuid}/pdf/shared)
 * lives OUTSIDE the ['auth', 'tenant.web'] group, so ResolveTenantWeb never
 * runs and there is no authenticated user to resolve a tenant from. The
 * tenant key is therefore carried IN the signed URL as the `tenant` query
 * param and re-initialised by PaymentReceiptController::sharedPdf(). Because
 * `tenant` is part of the signed payload, Laravel's `signed` middleware
 * rejects any request that tampers with it (or with the receipt uuid, or the
 * expiry) — the signature covers the whole URL.
 *
 * ~7-day expiry: long enough for a customer to open a WhatsApp link days
 * after it was sent, short enough that a leaked link doesn't live forever.
 * A voided receipt's link stops resolving regardless of expiry (the
 * controller 404s a void receipt).
 */
final class PaymentReceiptLink
{
    public const int EXPIRY_DAYS = 7;

    public static function shared(PaymentReceipt $receipt): string
    {
        return URL::temporarySignedRoute(
            'payment-receipts.pdf.shared',
            now()->addDays(self::EXPIRY_DAYS),
            [
                'receiptUuid' => $receipt->uuid,
                'tenant' => (string) (tenant()?->getTenantKey() ?? ''),
            ],
        );
    }
}
