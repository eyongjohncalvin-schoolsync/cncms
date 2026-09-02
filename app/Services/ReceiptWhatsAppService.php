<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentReceipt;
use App\Models\User;
use App\Support\CameroonPhone;
use App\Support\PaymentReceiptLink;

/**
 * Manual (free, no-Twilio) WhatsApp delivery of a business-issued payment
 * receipt — Wave 3 of docs/plans/payment-receipts-and-whatsapp.md and the
 * two-mode model in
 * .claude/skills/cncms-context/references/bill-notifications.md §1.
 *
 * The direct sibling of App\Services\BillNotificationService: composes a
 * short plain-text confirmation and a wa.me deep link a staff member opens
 * to send from their own WhatsApp session. No Twilio call, no
 * template-approval requirement. Bulk/Twilio media sending is deferred (see
 * the plan doc) — nothing here touches it.
 *
 * Everything is read from the receipt's frozen `snapshot`, never from live
 * customer/company/payment rows, so an edit or manuscript recalc after
 * issue can never change what a sent receipt link says. The signed public
 * PDF URL is built by App\Support\PaymentReceiptLink::shared() (Wave 2) —
 * not re-implemented here.
 */
final class ReceiptWhatsAppService
{
    public const string CHANNEL_MANUAL = 'whatsapp_manual';

    /**
     * Short receipt-confirmation text: greeting with the customer name, the
     * amount + receipt number, the signed PDF link, a company sign-off.
     * Amounts are formatted the same way BillNotificationService formats
     * FCFA (no decimals, thousands separator).
     */
    public function composeMessage(PaymentReceipt $receipt): string
    {
        $snapshot = $receipt->snapshot ?? [];

        $name = $snapshot['customer']['name'] ?? 'there';
        $company = $snapshot['company']['name'] ?? 'us';
        $number = $snapshot['receipt_number'] ?? $receipt->receipt_number;
        $amount = number_format((float) ($snapshot['amount'] ?? $receipt->amount), 0, '.', ',');
        $url = PaymentReceiptLink::shared($receipt);

        $lines = [
            "Hello {$name}, this is {$company}.",
            "Payment of {$amount} FCFA received — receipt {$number}.",
            "View or download your receipt here: {$url}",
            'Thank you for staying connected.',
        ];

        return implode(' ', $lines);
    }

    /**
     * The full wa.me deep link (message pre-filled), or null when the
     * receipt's snapshot customer has no phone that normalises to a
     * plausible Cameroon mobile number.
     */
    public function manualLink(PaymentReceipt $receipt): ?string
    {
        $phone = $this->recipientPhone($receipt);

        if ($phone === null) {
            return null;
        }

        return "https://wa.me/{$phone}?text=".rawurlencode($this->composeMessage($receipt));
    }

    /**
     * The normalised recipient phone from the frozen snapshot, or null.
     * Callers that need to tell "no phone" apart from a collapsed null link
     * (the controllers) use this directly.
     */
    public function recipientPhone(PaymentReceipt $receipt): ?string
    {
        return CameroonPhone::forWhatsapp($receipt->snapshot['customer']['phone'] ?? null);
    }

    /**
     * Append a send record to the receipt's `sent_log` jsonb array — the
     * audit trail of "we sent this receipt to the customer".
     */
    public function recordSent(PaymentReceipt $receipt, string $channel, User $actor, string $to): void
    {
        $log = $receipt->sent_log ?? [];

        $log[] = [
            'channel' => $channel,
            'at' => now()->toIso8601String(),
            'by' => $actor->id,
            'to' => $to,
        ];

        $receipt->update(['sent_log' => $log]);
    }
}
