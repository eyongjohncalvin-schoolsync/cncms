<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "One device tried wrong passwords on many different accounts" — to every
 * landlord, at most once an hour per IP (App\Services\LoginGuard).
 */
class LoginAttackAlertMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $ipAddress,
        public readonly int $accounts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Security alert: one device tried {$this->accounts} different CNCMS accounts");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.login-attack-alert');
    }
}
