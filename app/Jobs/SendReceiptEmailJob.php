<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ReceiptMail;
use App\Models\Company;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\PaymentReceiptService;
use App\Services\ReceiptEmailService;
use App\Services\ReceiptWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Emails one payment receipt with its PDF attached and records the send in
 * the receipt's sent_log. Runs in the receipt's tenant (Stancl's
 * QueueTenancyBootstrapper restores it). Re-checks everything at send time
 * — the receipt may have been voided, or the email removed, since it was
 * queued — and quietly does nothing in that case.
 */
class SendReceiptEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> seconds between attempts (Brevo hiccups are usually brief) */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $receiptId,
        public readonly ?int $actorId = null,
    ) {}

    public function handle(PaymentReceiptService $receipts, ReceiptEmailService $email, ReceiptWhatsAppService $log): void
    {
        $receipt = PaymentReceipt::query()->with('payment.customer')->find($this->receiptId);

        if ($receipt === null || $email->unavailableReason($receipt) !== null) {
            return;
        }

        $to = (string) $email->recipientEmail($receipt);
        $pdf = Storage::disk($receipts->disk())->get($receipts->pdf($receipt));

        Mail::to($to)->send(new ReceiptMail($receipt, (string) $pdf, Company::cached()?->email));

        $log->recordSent($receipt, ReceiptEmailService::CHANNEL, $this->actorId ? User::query()->find($this->actorId) : null, $to);
    }
}
