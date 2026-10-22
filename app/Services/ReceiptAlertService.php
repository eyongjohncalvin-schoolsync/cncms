<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentReceipt;
use App\Models\ReceiptLeaf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * What the public receipt check page reports back to the office
 * (docs/plans/cash-controls.md, wave 2).
 *
 *  - A code that doesn't match a recorded receipt is the clearest sign of
 *    an altered paper — everyone with `cash.view` is told at once (at most
 *    once an hour per receipt, so a curious customer re-scanning doesn't
 *    flood the bell).
 *  - A real but still-unused number being scanned means a customer holds a
 *    receipt for money nobody has recorded yet. Collectors working offline
 *    legitimately sync later, so this only stamps `scanned_unused_at`; the
 *    alerts (wave 6) raise it once the number is still unused past the
 *    company's cash-handover deadline.
 */
class ReceiptAlertService
{
    public function __construct(
        private readonly CashNotificationService $notifier,
    ) {}

    public function codeMismatch(PaymentReceipt $receipt, string $givenCode, ?string $ip): void
    {
        Log::warning('Receipt check: security code mismatch', [
            'receipt_number' => $receipt->receipt_number,
            'given_code' => $givenCode,
            'ip' => $ip,
        ]);

        if (! Cache::add("receipt-check:mismatch:{$receipt->id}", true, now()->addHour())) {
            return;
        }

        $this->notifier->toHoldersOf(
            'cash.view',
            'receipts.code_mismatch',
            'warning',
            'Possible altered receipt',
            "Someone checked receipt {$receipt->receipt_number} with a security code that does not match what was recorded. The paper may have been altered — contact the customer.",
            '/receipt-stock?search='.urlencode($receipt->receipt_number),
            'payment_receipt',
            $receipt->uuid,
        );
    }

    public function unreportedScan(ReceiptLeaf $leaf, ?string $givenCode, ?string $ip): void
    {
        Log::info('Receipt check: unused receipt number scanned', [
            'receipt_number' => $leaf->number,
            'given_code' => $givenCode,
            'ip' => $ip,
        ]);

        if ($leaf->scanned_unused_at === null) {
            $leaf->forceFill(['scanned_unused_at' => now()])->save();
        }
    }
}
