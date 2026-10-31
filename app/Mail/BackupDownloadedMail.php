<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells every landlord that a full database backup was downloaded
 * (App\Http\Controllers\Landlord\BackupController::download()) — the file
 * holds every tenant's data, so a download nobody expected must be noticed.
 */
class BackupDownloadedMail extends Mailable
{
    use Queueable;

    /**
     * @param  string  $occurredAt  Already formatted in Cameroon time.
     */
    public function __construct(
        public readonly string $recipientName,
        public readonly string $downloadedBy,
        public readonly string $fileName,
        public readonly string $occurredAt,
        public readonly ?string $ipAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'CNCMS: a database backup was downloaded');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.backup-downloaded');
    }
}
