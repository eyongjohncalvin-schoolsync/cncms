<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Issues, voids and renders payment receipts
 * (docs/plans/payment-receipts-and-whatsapp.md, Wave 1).
 *
 * Wiring convention: this service is called EXPLICITLY from
 * App\Services\PaymentVerificationService::verify() (auto-issue on approve,
 * void on a later reject) — this codebase deliberately wires side effects at
 * the call site rather than via model observers (see
 * App\Services\NotificationService's class doc). The hook there is a
 * one-liner delegating to issueFor() / voidForPayment().
 *
 * The receipt PDF is ALWAYS rendered from the frozen `snapshot`, never from
 * live customer/company/payment data — an edit or manuscript recalc after
 * issue must never change an already-issued receipt.
 */
class PaymentReceiptService
{
    /**
     * Issue a receipt for $payment, or return the existing one.
     *
     * Idempotent: a second call for a payment that already has a non-void
     * receipt returns that same row (same number, same snapshot). If the
     * payment's receipt was previously voided (rejected, then re-approved
     * through verify()), the SAME row is re-activated — status back to
     * `issued`, snapshot rebuilt, pdf cache dropped — keeping its already
     * allocated receipt_number (the `payment_id` UNIQUE constraint means
     * there can only ever be one row per payment).
     */
    public function issueFor(Payment $payment, ?User $actor = null): PaymentReceipt
    {
        $payment->loadMissing(['customer.zone.branch', 'verification']);

        $existing = PaymentReceipt::query()->where('payment_id', $payment->id)->first();

        if ($existing && ! $existing->isVoid()) {
            return $existing;
        }

        $snapshot = $this->buildSnapshot($payment);

        if ($existing) {
            // Re-activate the voided receipt in place — reject → re-approve.
            // Keeps its already-allocated receipt_number.
            $existing->update([
                'status' => PaymentReceipt::STATUS_ISSUED,
                'issued_at' => now(),
                'issued_by' => $actor?->id,
                'amount' => $payment->amount,
                'snapshot' => array_merge($snapshot, ['receipt_number' => $existing->receipt_number]),
                'pdf_path' => null,
                'pdf_disk' => null,
            ]);

            return $existing->refresh();
        }

        return DB::transaction(function () use ($payment, $actor, $snapshot): PaymentReceipt {
            $number = $this->allocateNumber();

            return PaymentReceipt::create([
                'payment_id' => $payment->id,
                'receipt_number' => $number,
                'issued_at' => now(),
                'issued_by' => $actor?->id,
                'amount' => $payment->amount,
                'snapshot' => array_merge($snapshot, ['receipt_number' => $number]),
                'sent_log' => [],
                'status' => PaymentReceipt::STATUS_ISSUED,
            ]);
        });
    }

    /**
     * Mark a receipt void, keeping the row (audit). No-op if already void.
     */
    public function void(PaymentReceipt $receipt): void
    {
        if ($receipt->isVoid()) {
            return;
        }

        $receipt->update(['status' => PaymentReceipt::STATUS_VOID]);
    }

    /**
     * Void the receipt attached to $payment, if any — the verify() reject
     * branch's one-liner. No-op when the payment has no receipt.
     */
    public function voidForPayment(Payment $payment): void
    {
        $receipt = PaymentReceipt::query()->where('payment_id', $payment->id)->first();

        if ($receipt) {
            $this->void($receipt);
        }
    }

    /**
     * Disk-relative path to the receipt PDF, rendering + caching it on the
     * configured default disk on first call (or if the cached file is gone).
     * Renders strictly from `snapshot`.
     */
    public function pdf(PaymentReceipt $receipt): string
    {
        $disk = $this->disk();

        if (
            $receipt->pdf_path !== null
            && $receipt->pdf_disk === $disk
            && Storage::disk($disk)->exists($receipt->pdf_path)
        ) {
            return $receipt->pdf_path;
        }

        $tenantId = (string) (tenant()?->getTenantKey() ?? 'central');
        $path = "payment-receipts/{$tenantId}/{$receipt->uuid}.pdf";

        $pdf = Pdf::loadView('pdf.receipt', [
            'r' => $receipt->snapshot ?? [],
            // Branding only — matched to bill.blade.php, fetched live (not
            // frozen in the snapshot, which would bloat every row with a
            // base64 image). A logo change is cosmetic, not receipt data.
            'logo' => Company::cached()?->logoDataUri(),
        ])->setPaper('a6', 'portrait');

        Storage::disk($disk)->put($path, $pdf->output());

        $receipt->forceFill(['pdf_path' => $path, 'pdf_disk' => $disk])->save();

        return $path;
    }

    /**
     * Follows the app's configured default disk, exactly like
     * App\Services\BillBatchService::disk() — `local` (private) on the VPS,
     * an object-storage disk on an ephemeral host (set FILESYSTEM_DISK).
     */
    private function disk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    /**
     * Mint the next `RCP-{YYYY}-{6-digit}` number. MUST be called inside a
     * transaction — the `FOR UPDATE` lock on the year's `receipt_counters`
     * row serialises concurrent allocations (two verify() calls landing at
     * once can't read the same `next_number`). Gap-tolerant: if the caller's
     * transaction later rolls back, the number is simply skipped.
     */
    private function allocateNumber(): string
    {
        $year = (int) now()->format('Y');

        // Create the year's row if this is the first receipt of the year;
        // insertOrIgnore keeps a concurrent creator from erroring.
        DB::table('receipt_counters')->insertOrIgnore([
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('receipt_counters')->where('year', $year)->lockForUpdate()->first();
        $number = (int) ($row->next_number ?? 1);

        DB::table('receipt_counters')
            ->where('year', $year)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return sprintf('RCP-%04d-%06d', $year, $number);
    }

    /**
     * Freeze everything the receipt shows. Read once here, never again from
     * live data.
     *
     * @return array<string, mixed>
     */
    private function buildSnapshot(Payment $payment): array
    {
        $customer = $payment->customer;
        $company = Company::cached();

        return [
            'issued_at' => now()->toIso8601String(),
            'customer' => [
                'name' => $customer?->name,
                'phone' => $customer?->phone,
                'zone' => $customer?->zone?->name,
                'branch' => $customer?->zone?->branch?->name,
                'code' => $customer?->uuid ? substr((string) $customer->uuid, 0, 8) : null,
            ],
            'payment' => [
                'uuid' => $payment->uuid,
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'method' => $payment->frequency,
                'frequency' => $payment->frequency,
                'months' => $payment->months,
                'periods' => $this->coveredPeriods($payment),
                'expiration_date' => $payment->expiration_date?->toDateString(),
                'momo_ref' => $payment->verification?->momo_ref,
                'recorded_offline' => (bool) $payment->recorded_offline,
                'collected_at' => $payment->collected_at?->toIso8601String(),
                'recorded_at' => $payment->created_at?->toIso8601String(),
            ],
            'company' => [
                'name' => $company?->name,
                'location' => $company?->location,
                'head_office' => $company?->head_office,
                'tech_number' => $company?->tech_number,
                'momo_number' => $company?->momo_number,
                'momo_name' => $company?->momo_name,
                'rccm_number' => $company?->rccm_number,
                'niu' => $company?->niu,
            ],
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
        ];
    }

    /**
     * The billing month(s) this payment pays for. A `monthly` payment covers
     * one month (its `processed_period` once consumed, else the month it was
     * collected/recorded in); a `months`/`yearly` prepayment covers that many
     * consecutive months from the collection month.
     *
     * @return list<string>  YYYY-MM, chronological
     */
    private function coveredPeriods(Payment $payment): array
    {
        $start = $payment->processed_period
            ?? ($payment->collected_at ?? $payment->created_at ?? now())->format('Y-m');

        $count = match ($payment->frequency) {
            'months' => (int) ($payment->months ?: 1),
            'yearly' => (int) ($payment->months ?: 12),
            default => 1,
        };

        $count = max(1, $count);

        // '!Y-m' — the `!` resets day/time to the epoch base before Y/M are
        // applied, so parsing on the 31st can't overflow the month (the
        // load-bearing gotcha documented in bill-printing.md §6).
        $cursor = Carbon::createFromFormat('!Y-m', $start);

        $periods = [];
        for ($i = 0; $i < $count; $i++) {
            $periods[] = $cursor->copy()->addMonthsNoOverflow($i)->format('Y-m');
        }

        return $periods;
    }
}
