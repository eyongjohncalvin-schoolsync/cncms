<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your account's security settings changed" notice
 * (App\Services\AccountSecurityService::notify()) — so the account owner
 * notices a change they did not make themselves.
 */
class SecurityNoticeMail extends Mailable
{
    use Queueable;

    public const string PASSWORD_CHANGED = 'password_changed';

    public const string EMAIL_CHANGED = 'email_changed';

    public const string TWO_FACTOR_ENABLED = 'two_factor_enabled';

    public const string TWO_FACTOR_DISABLED = 'two_factor_disabled';

    public const string RECOVERY_CODES_REGENERATED = 'recovery_codes_regenerated';

    public const string PASSWORD_RESET = 'password_reset';

    public const string TWO_FACTOR_RESET = 'two_factor_reset';

    /**
     * @param  string  $kind  One of the constants above.
     * @param  string  $occurredAt  Already formatted in Cameroon time.
     */
    public function __construct(
        public readonly string $recipientName,
        public readonly string $kind,
        public readonly string $occurredAt,
        public readonly ?string $ipAddress = null,
        public readonly ?string $newEmail = null,
    ) {}

    public static function headline(string $kind): string
    {
        return match ($kind) {
            self::PASSWORD_CHANGED => 'Your password was changed',
            self::EMAIL_CHANGED => 'Your email address was changed',
            self::TWO_FACTOR_ENABLED => 'Two-factor authentication turned on',
            self::TWO_FACTOR_DISABLED => 'Two-factor authentication turned off',
            self::RECOVERY_CODES_REGENERATED => 'New recovery codes were generated',
            self::PASSWORD_RESET => 'Your password was reset',
            self::TWO_FACTOR_RESET => 'Two-factor authentication was reset by support',
            default => 'Your account security settings changed',
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'CNCMS: '.self::headline($this->kind));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security-notice',
            with: [
                'recipientName' => $this->recipientName,
                'kind' => $this->kind,
                'headline' => self::headline($this->kind),
                'occurredAt' => $this->occurredAt,
                'ipAddress' => $this->ipAddress,
                'newEmail' => $this->newEmail,
            ],
        );
    }
}
