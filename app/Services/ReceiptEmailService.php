<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendReceiptEmailJob;
use App\Models\NotificationSetting;
use App\Models\PaymentReceipt;
use App\Models\User;

/**
 * Emailing payment receipts (Brevo SMTP in production — see .env.example).
 * The email channel must be switched on in Settings → Notifications; with
 * "auto-send receipts" also on, a receipt is emailed the moment its payment
 * is verified to every customer who prefers email and has an address.
 * Sending itself is queued (SendReceiptEmailJob), dispatched after the
 * surrounding transaction commits.
 */
class ReceiptEmailService
{
    public const string CHANNEL = 'email';

    /**
     * Where the receipt would go — the customer's current email (it may
     * have been added after the receipt was issued), or null.
     */
    public function recipientEmail(PaymentReceipt $receipt): ?string
    {
        $email = $receipt->payment?->customer?->email;

        return $email !== null && $email !== '' ? $email : null;
    }

    public function emailEnabled(): bool
    {
        return (bool) NotificationSetting::cached()->email_enabled;
    }

    /**
     * Why this receipt can't be emailed right now, or null if it can.
     */
    public function unavailableReason(PaymentReceipt $receipt): ?string
    {
        return match (true) {
            $receipt->isVoid() => 'This receipt has been voided.',
            ! $this->emailEnabled() => 'Email is switched off in Settings → Notifications.',
            $this->recipientEmail($receipt) === null => 'This customer has no email address on file.',
            default => null,
        };
    }

    public function queue(PaymentReceipt $receipt, ?User $actor): void
    {
        SendReceiptEmailJob::dispatch($receipt->id, $actor?->id)->afterCommit();
    }

    /**
     * Called whenever a receipt is issued: emails it straight away when
     * either
     *   - auto-send is on and this customer prefers email, or
     *   - collection confirmations are on (docs/plans/cash-controls.md) —
     *     every customer with an address hears from the company the moment
     *     their money is recorded, whatever channel they prefer for the
     *     receipt itself. A customer who paid and got nothing calls the
     *     office: that is the control.
     */
    public function autoSendIfWanted(PaymentReceipt $receipt): void
    {
        $settings = NotificationSetting::cached();

        if (! $settings->email_enabled || $this->unavailableReason($receipt) !== null) {
            return;
        }

        $prefersEmail = $receipt->payment?->customer?->preferred_channel === 'email';

        if (($settings->auto_send_receipts && $prefersEmail) || $settings->email_collection_confirmations) {
            $this->queue($receipt, null);
        }
    }
}
