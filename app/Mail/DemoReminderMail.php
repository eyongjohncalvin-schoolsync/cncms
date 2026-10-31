<?php

declare(strict_types=1);

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The free demo's reminders (App\Console\Commands\DemoReminders): 30, 7 and
 * 1 day(s) before it ends, then "it has ended, the system is read-only" —
 * to the company's super/admin users. `forLandlord` is the copy every
 * landlord gets when a demo ends, so they can follow up and activate it.
 */
class DemoReminderMail extends Mailable
{
    use Queueable;

    /**
     * @param  '30'|'7'|'1'|'ended'  $stage
     */
    public function __construct(
        public readonly string $stage,
        public readonly string $companyName,
        public readonly string $endsOn,
        public readonly string $recipientName,
        public readonly bool $forLandlord = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: match (true) {
                $this->forLandlord => "Free demo ended: {$this->companyName}",
                $this->stage === 'ended' => 'Your CNCMS free demo has ended',
                $this->stage === '1' => 'Your CNCMS free demo ends tomorrow',
                default => "Your CNCMS free demo ends in {$this->stage} days",
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.demo-reminder',
            with: [
                'stage' => $this->stage,
                'companyName' => $this->companyName,
                'endsOn' => CarbonImmutable::parse($this->endsOn)->format('l j F Y'),
                'recipientName' => $this->recipientName,
                'forLandlord' => $this->forLandlord,
                'phone' => config('site.phone'),
                'whatsapp' => config('site.whatsapp'),
                'email' => config('site.email'),
                'company' => config('site.company'),
            ],
        );
    }
}
