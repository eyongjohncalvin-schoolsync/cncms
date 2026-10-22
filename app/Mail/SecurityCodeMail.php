<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SecurityCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A one-time 6-digit code (App\Services\SecurityCodeService): sign-in code,
 * two-factor fallback code, or new-email confirmation code. Platform
 * branded — it is sent before any tenant is resolved.
 */
class SecurityCodeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === SecurityCode::PURPOSE_EMAIL_CHANGE
                ? "{$this->code} is your code to confirm this email address"
                : "{$this->code} is your CNCMS sign-in code",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security-code',
            with: [
                'recipientName' => $this->recipientName,
                'code' => $this->code,
                'isEmailChange' => $this->purpose === SecurityCode::PURPOSE_EMAIL_CHANGE,
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }
}
