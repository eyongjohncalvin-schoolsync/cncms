<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Company;
use App\Models\Voucher;
use App\Support\AmountInWords;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "A voucher needs your approval" — sent to every investor (or the owner)
 * whose approval an above-limit voucher needs (docs/plans/cash-controls.md,
 * wave 5). Email is the channel for now; SMS / WhatsApp later.
 */
class VoucherApprovalMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly Voucher $voucher,
        public readonly string $approverName,
    ) {}

    public function envelope(): Envelope
    {
        $company = Company::cached()?->name ?? config('app.name');

        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) $company),
            subject: "Approval needed: voucher {$this->voucher->number} — ".number_format((float) $this->voucher->amount, 0, '.', ' ').' FCFA',
        );
    }

    public function content(): Content
    {
        $this->voucher->loadMissing('requestedBy');

        return new Content(
            view: 'emails.voucher-approval',
            with: [
                'approverName' => $this->approverName,
                'companyName' => Company::cached()?->name ?? config('app.name'),
                'voucher' => $this->voucher,
                'amountWords' => AmountInWords::convert((string) $this->voucher->amount),
                'reasons' => $this->voucher->tier_reasons ?? [],
                'url' => url("/vouchers/{$this->voucher->uuid}"),
            ],
        );
    }
}
