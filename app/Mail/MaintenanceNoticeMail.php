<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\MaintenanceWindow;
use App\Support\BusinessTimezone;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "The system will be unavailable from … to …" — sent to every tenant user
 * when a landlord schedules, moves or cancels a maintenance window
 * (App\Http\Controllers\Landlord\MaintenanceWindowController). Platform
 * branded, not tenant branded: it goes to users of every tenant and no
 * tenant is initialized when it is built.
 */
class MaintenanceNoticeMail extends Mailable
{
    use Queueable;

    /**
     * @param  'scheduled'|'changed'|'cancelled'  $kind
     */
    public function __construct(
        public readonly MaintenanceWindow $window,
        public readonly string $kind,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: match ($this->kind) {
                'cancelled' => 'Planned maintenance cancelled',
                'changed' => 'Planned maintenance — new end time',
                default => 'Planned maintenance: CNCMS will be unavailable',
            },
        );
    }

    public function content(): Content
    {
        $timezone = BusinessTimezone::WAT;

        return new Content(
            view: 'emails.maintenance-notice',
            with: [
                'kind' => $this->kind,
                'recipientName' => $this->recipientName,
                'startsAt' => $this->window->starts_at->copy()->setTimezone($timezone)->format('l d M Y, H:i'),
                'endsAt' => $this->window->ends_at->copy()->setTimezone($timezone)->format('l d M Y, H:i'),
                'message' => $this->window->message,
            ],
        );
    }
}
