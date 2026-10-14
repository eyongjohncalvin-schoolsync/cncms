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
     * auto-send is on and this customer prefers email.
     */
    public function autoSendIfWanted(PaymentReceipt $receipt): void
    {
        $settings = NotificationSetting::cached();

        if (! $settings->auto_send_receipts || ! $settings->email_enabled) {
            return;
        }

        if ($receipt->payment?->customer?->preferred_channel !== 'email' || $this->unavailableReason($receipt) !== null) {
            return;
        }

        $this->queue($receipt, null);
    }
}
