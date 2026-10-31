<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Someone typed a wrong password N times; password sign-in is blocked
 * until …" — to the account's owner when App\Services\LoginGuard starts a
 * block. Platform branded: no tenant is initialized at sign-in.
 */
class AccountBlockedMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
        public readonly int $failures,
        public readonly string $blockedUntil,
        public readonly string $ipAddress,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'CNCMS: password sign-in blocked after wrong passwords');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-blocked');
    }
}
