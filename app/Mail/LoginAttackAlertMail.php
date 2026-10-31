<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "One device tried wrong passwords on many different accounts" — to every
 * landlord, at most once an hour per IP — or, with $blockedUntil, "and
 * sign-in from it is now blocked" (the device block). App\Services\LoginGuard.
 */
class LoginAttackAlertMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $ipAddress,
        public readonly int $accounts,
        public readonly ?string $blockedUntil = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->blockedUntil !== null
            ? "Security alert: sign-in blocked from {$this->ipAddress}"
            : "Security alert: one device tried {$this->accounts} different CNCMS accounts");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.login-attack-alert');
    }
}
