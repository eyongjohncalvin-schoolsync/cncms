<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\PaymentReceipt;
use App\Support\PaymentReceiptLink;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A payment receipt by email, the PDF attached. Built from the receipt's
 * frozen snapshot like the PDF itself. Sent from the platform address
 * (MAIL_FROM_ADDRESS, e.g. no-reply@cncms.tech via Brevo) under the
 * tenant's company name, with replies going to the company's own email.
 * Sent by App\Jobs\SendReceiptEmailJob, never directly.
 */
class ReceiptMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly PaymentReceipt $receipt,
        private readonly string $pdfBytes,
        private readonly ?string $companyEmail,
    ) {}

    public function envelope(): Envelope
    {
        $company = $this->receipt->snapshot['company']['name'] ?? config('app.name');

        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) $company),
            replyTo: $this->companyEmail ? [new Address($this->companyEmail, (string) $company)] : [],
            subject: "Payment receipt {$this->receipt->receipt_number} — {$company}",
        );
    }

    public function content(): Content
    {
        $snapshot = $this->receipt->snapshot ?? [];

        return new Content(
            view: 'emails.receipt',
            with: [
                'customerName' => $snapshot['customer']['name'] ?? null,
                'companyName' => $snapshot['company']['name'] ?? config('app.name'),
                'receiptNumber' => $this->receipt->receipt_number,
                'amount' => (string) $this->receipt->amount,
                'issuedAt' => $this->receipt->issued_at,
                'balance' => $snapshot['balance'] ?? null,
                'verifyUrl' => PaymentReceiptLink::check($this->receipt),
                'securityCode' => $this->receipt->formattedSecurityCode(),
                'momoNumber' => $snapshot['company']['momo_number'] ?? null,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdfBytes, "receipt-{$this->receipt->receipt_number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
