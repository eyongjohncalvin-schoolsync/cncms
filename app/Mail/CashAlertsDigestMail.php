<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The morning cash-controls digest (docs/plans/cash-controls.md, wave 6):
 * the same alerts the Cash alerts page shows, emailed to managers, the owner
 * and the investors so nothing waits for someone to open the app.
 */
class CashAlertsDigestMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{key: string, severity: string, title: string, advice: string, items: list<array<string, mixed>>}>  $alerts
     */
    public function __construct(
        public readonly array $alerts,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        $company = Company::cached()?->name ?? config('app.name');
        $count = array_sum(array_map(fn (array $alert): int => count($alert['items']), $this->alerts));

        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) $company),
            subject: "Cash controls: {$count} item(s) need attention — {$company}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cash-alerts-digest',
            with: [
                'alerts' => $this->alerts,
                'recipientName' => $this->recipientName,
                'companyName' => Company::cached()?->name ?? config('app.name'),
                'url' => url('/cash/alerts'),
            ],
        );
    }
}
