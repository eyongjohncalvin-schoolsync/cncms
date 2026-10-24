<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerCharge;
use App\Models\FieldDevice;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Models\User;
use App\Support\AmountInWords;
use App\Support\BranchLookup;
use App\Support\PaymentReceiptLink;
use App\Support\QrCode;
use App\Support\ReceiptSecurityCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
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
    /** A6 sheet — the original office receipt. */
    public const string FORMAT_A6 = 'a6';

    /** Roll receipts for pocket Bluetooth printers carried by field agents. */
    public const string FORMAT_THERMAL_58 = 'thermal58';

    public const string FORMAT_THERMAL_80 = 'thermal80';

    public const array FORMATS = [self::FORMAT_A6, self::FORMAT_THERMAL_58, self::FORMAT_THERMAL_80];

    /** Paper width per roll format; the printable area is a few mm narrower. */
    private const array THERMAL_WIDTH_MM = [self::FORMAT_THERMAL_58 => 58, self::FORMAT_THERMAL_80 => 80];

    /** Bumped whenever the receipt layout changes; see pdf(). */
    private const int LAYOUT_VERSION = 6;

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
    public function __construct(
        private readonly ReceiptEmailService $email,
    ) {}

    /**
     * $autoSend: email the new receipt straight away when auto-send is on
     * and the customer prefers email (ReceiptEmailService::autoSendIfWanted)
     * — false for the historical backfill, which must never email old
     * receipts out of the blue.
     */
    public function issueFor(Payment $payment, ?User $actor = null, bool $autoSend = true): PaymentReceipt
    {
        BranchLookup::remember([$payment]);
        $payment->loadMissing(['customer.zone.branch', 'verification', 'recordedBy']);

        $existing = $payment->relationLoaded('receipt')
            ? $payment->receipt
            : PaymentReceipt::query()->where('payment_id', $payment->id)->first();

        if ($existing && ! $existing->isVoid()) {
            return $existing;
        }

        $snapshot = $this->buildSnapshot($payment);

        if ($existing) {
            // Re-activate the voided receipt in place — reject → re-approve.
            // Keeps its already-allocated receipt_number; the security code
            // is re-signed because the issue time (and maybe the amount)
            // changed. A field receipt keeps its field code: the paper the
            // customer holds still carries it.
            $issuedAt = now();
            $code = $existing->source === PaymentReceipt::SOURCE_OFFICE || $existing->security_code === null
                ? $this->officeCode($existing->receipt_number, $payment, $issuedAt)
                : $existing->security_code;

            $existing->update([
                'status' => PaymentReceipt::STATUS_ISSUED,
                'issued_at' => $issuedAt,
                'issued_by' => $actor?->id,
                'amount' => $payment->amount,
                'security_code' => $code,
                'snapshot' => array_merge($snapshot, ['receipt_number' => $existing->receipt_number, 'security_code' => $code]),
                'pdf_path' => null,
                'pdf_disk' => null,
            ]);

            $existing->refresh();

            if ($autoSend) {
                $this->email->autoSendIfWanted($existing->setRelation('payment', $payment));
            }

            return $existing;
        }

        $receipt = DB::transaction(function () use ($payment, $actor, $snapshot): PaymentReceipt {
            $number = $this->allocateNumber();
            $issuedAt = now();
            $code = $this->officeCode($number, $payment, $issuedAt);

            return PaymentReceipt::create([
                'payment_id' => $payment->id,
                'receipt_number' => $number,
                'issued_at' => $issuedAt,
                'issued_by' => $actor?->id,
                'amount' => $payment->amount,
                'source' => PaymentReceipt::SOURCE_OFFICE,
                'security_code' => $code,
                'snapshot' => array_merge($snapshot, ['receipt_number' => $number, 'security_code' => $code]),
                'sent_log' => [],
                'status' => PaymentReceipt::STATUS_ISSUED,
            ]);
        });

        $payment->setRelation('receipt', $receipt);

        if ($autoSend) {
            $this->email->autoSendIfWanted($receipt->setRelation('payment', $payment));
        }

        return $receipt;
    }

    /**
     * The receipt a collector already handed over in the field — a number
     * from their digital block (signed on the phone with the device secret)
     * or a leaf of their printed book (docs/plans/cash-controls.md). Called
     * by App\Services\FieldReceiptService once it has checked the leaf and
     * the code, inside the same transaction that created the payment; the
     * leaf is marked used here so the two can never disagree.
     *
     * The receipt exists from the moment of collection, even while the
     * payment itself still awaits verification — a later rejection voids it
     * exactly like an office receipt.
     */
    public function issueField(Payment $payment, ReceiptLeaf $leaf, CarbonInterface $issuedAt, ?FieldDevice $device, ?string $deviceCode, ?User $actor = null): PaymentReceipt
    {
        BranchLookup::remember([$payment]);
        $payment->loadMissing(['customer.zone.branch', 'verification', 'recordedBy']);

        $source = $leaf->block->kind === ReceiptBlock::KIND_DIGITAL ? PaymentReceipt::SOURCE_DIGITAL : PaymentReceipt::SOURCE_BOOK;

        // A book leaf carries no printed code (its amount is handwritten);
        // the server signs one anyway so the email confirmation and the
        // verify page can quote it.
        $code = $source === PaymentReceipt::SOURCE_DIGITAL && $deviceCode !== null
            ? ReceiptSecurityCode::normalize($deviceCode)
            : $this->officeCode($leaf->number, $payment, $issuedAt);

        $snapshot = array_merge($this->buildSnapshot($payment), [
            'issued_at' => $issuedAt->toIso8601String(),
            'receipt_number' => $leaf->number,
            'security_code' => $code,
            'source' => $source,
        ]);

        $receipt = PaymentReceipt::create([
            'payment_id' => $payment->id,
            'receipt_number' => $leaf->number,
            'issued_at' => $issuedAt,
            'issued_by' => $actor?->id,
            'amount' => $payment->amount,
            'source' => $source,
            'security_code' => $code,
            'receipt_leaf_id' => $leaf->id,
            'field_device_id' => $device?->id,
            'snapshot' => $snapshot,
            'sent_log' => [],
            'status' => PaymentReceipt::STATUS_ISSUED,
        ]);

        $leaf->update(['status' => ReceiptLeaf::STATUS_USED, 'used_at' => $issuedAt]);

        $payment->setRelation('receipt', $receipt);

        return $receipt;
    }

    /** Email the confirmation for a just-issued receipt when the settings want it. */
    public function autoSend(PaymentReceipt $receipt, Payment $payment): void
    {
        $this->email->autoSendIfWanted($receipt->setRelation('payment', $payment));
    }

    /**
     * Signs an office receipt with the tenant's server key — see
     * App\Support\ReceiptSecurityCode.
     */
    private function officeCode(string $receiptNumber, Payment $payment, CarbonInterface $issuedAt): string
    {
        $payment->loadMissing('customer');

        return ReceiptSecurityCode::compute(
            ReceiptSecurityCode::officeKey((string) (tenant()?->getTenantKey() ?? 'central')),
            ReceiptSecurityCode::canonical($receiptNumber, (string) $payment->customer?->uuid, (string) $payment->amount, $issuedAt->getTimestamp()),
        );
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
    public function pdf(PaymentReceipt $receipt, string $format = self::FORMAT_A6, bool $duplicate = false): string
    {
        $format = in_array($format, self::FORMATS, true) ? $format : self::FORMAT_A6;
        $disk = $this->disk();
        $isOriginalA6 = $format === self::FORMAT_A6 && ! $duplicate;

        // The layout version is part of the cached file name, so receipts
        // rendered before a layout change (e.g. before the QR code) are
        // re-rendered once instead of serving the stale file.
        $tenantId = (string) (tenant()?->getTenantKey() ?? 'central');
        $path = "payment-receipts/{$tenantId}/{$receipt->uuid}-v".self::LAYOUT_VERSION."-{$format}".($duplicate ? '-copy' : '').'.pdf';

        if (Storage::disk($disk)->exists($path) && (! $isOriginalA6 || ($receipt->pdf_path === $path && $receipt->pdf_disk === $disk))) {
            return $path;
        }

        $verifyUrl = PaymentReceiptLink::check($receipt);
        $data = [
            'r' => $receipt->snapshot ?? [],
            // Branding only — matched to bill.blade.php, fetched live (not
            // frozen in the snapshot, which would bloat every row with a
            // base64 image). A logo change is cosmetic, not receipt data.
            'logo' => Company::cached()?->logoDataUri(),
            'qr' => QrCode::pngDataUri($verifyUrl),
            'duplicate' => $duplicate,
        ];

        $pdf = $format === self::FORMAT_A6
            ? Pdf::loadView('pdf.receipt', $data)->setPaper('a6', 'portrait')
            : Pdf::loadView('pdf.receipt-thermal', [...$data, 'widthMm' => self::THERMAL_WIDTH_MM[$format]])
                ->setPaper([0, 0, self::mmToPoints(self::THERMAL_WIDTH_MM[$format]), self::mmToPoints($this->thermalLengthMm($receipt, $format, $duplicate))], 'portrait');

        Storage::disk($disk)->put($path, $pdf->output());

        if ($isOriginalA6) {
            $receipt->forceFill(['pdf_path' => $path, 'pdf_disk' => $disk])->save();
        }

        return $path;
    }

    /**
     * Counts a staff print/download and says whether it is a reprint —
     * every print after the first carries a "DUPLICATE" stamp, so a second
     * paper copy can't pass as a second payment.
     */
    public function recordPrint(PaymentReceipt $receipt): bool
    {
        $isReprint = $receipt->print_count > 0;

        $receipt->newQuery()->whereKey($receipt->id)->increment('print_count');
        $receipt->print_count++;

        return $isReprint;
    }

    /**
     * How much roll a receipt needs — the page is cut to the content so a
     * pocket printer doesn't feed blank paper: a fixed body plus one line
     * per optional row, the logo and the duplicate stamp. Slightly generous
     * so nothing spills onto a second page.
     */
    private function thermalLengthMm(PaymentReceipt $receipt, string $format, bool $duplicate): int
    {
        $narrow = self::THERMAL_WIDTH_MM[$format] <= 58;
        $snapshot = $receipt->snapshot ?? [];
        $payment = $snapshot['payment'] ?? [];

        $optionalRows = count(array_filter([
            $snapshot['customer']['code'] ?? null,
            $snapshot['customer']['zone'] ?? null,
            $snapshot['invoice']['number'] ?? null,
            in_array($payment['method'] ?? '', ['months', 'yearly'], true) ? 'prepaid' : null,
            $payment['momo_ref'] ?? null,
            $payment['collected_by'] ?? null,
            $snapshot['balance'] ?? null,
            $snapshot['company']['rccm_number'] ?? null,
            $snapshot['company']['niu'] ?? null,
            // Cash controls: paid-by, txn ID, the code (under the number and
            // under the QR), two lines of amount in words, the QR notice.
            $payment['paid_by_label'] ?? null,
            $payment['method_reference'] ?? null,
            $snapshot['security_code'] ?? null,
            $snapshot['security_code'] ?? null,
            $snapshot['amount_in_words']['en'] ?? null,
            $snapshot['amount_in_words']['fr'] ?? null,
            'qr-notice',
            // Installation receipt: fee, paid so far, balance.
            ...(isset($snapshot['installation']) ? ['fee', 'paid', 'balance'] : []),
            // …plus its free months, advance and first bill lines (D11).
            ...array_fill(0, count($snapshot['installation']['services'] ?? []) + count($snapshot['installation']['waiting'] ?? []), 'service'),
            $snapshot['installation']['first_bill_label'] ?? null,
        ]));

        return ($narrow ? 82 : 98)
            + $optionalRows * ($narrow ? 4 : 5)
            + (Company::cached()?->logoDataUri() !== null ? ($narrow ? 12 : 15) : 0)
            + ($duplicate ? 7 : 0);
    }

    private static function mmToPoints(float $millimetres): float
    {
        return round($millimetres * 72 / 25.4, 2);
    }

    /**
     * Follows the app's configured default disk, exactly like
     * App\Services\BillBatchService::disk() — `local` (private) on the VPS,
     * an object-storage disk on an ephemeral host (set FILESYSTEM_DISK).
     */
    public function disk(): string
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
        ['year' => $year, 'first' => $number] = $this->reserveNumbers(1);

        return ReceiptLeaf::formatNumber($year, $number);
    }

    /**
     * Reserve $count consecutive receipt numbers of this year's series and
     * return where the range starts — one number for an office receipt, a
     * whole range for a receipt block (ReceiptStockService). Same lock,
     * same counter, so a number is unique across every kind of receipt.
     * MUST be called inside a transaction (see allocateNumber()).
     *
     * @return array{year: int, first: int}
     */
    public function reserveNumbers(int $count): array
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
        $first = (int) ($row->next_number ?? 1);

        DB::table('receipt_counters')
            ->where('year', $year)
            ->update(['next_number' => $first + $count, 'updated_at' => now()]);

        return ['year' => $year, 'first' => $first];
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
        $purpose = $payment->purpose ?? Payment::PURPOSE_SUBSCRIPTION;
        $isSubscription = $purpose === Payment::PURPOSE_SUBSCRIPTION;
        // A one-off charge's kind (installation / relocation /
        // re-installation) names the receipt; all three are `installation`
        // purpose money.
        $chargeKind = $purpose === Payment::PURPOSE_INSTALLATION ? $payment->customerCharge?->kind : null;

        return [
            'issued_at' => now()->toIso8601String(),
            // What the money was for (docs/plans/installation-and-promotions.md):
            // drives the receipt heading and which balance it shows. A
            // snapshot without it is an older subscription receipt.
            'purpose' => $purpose,
            'charge_kind' => $chargeKind,
            'title' => CustomerCharge::RECEIPT_TITLES[$chargeKind ?? ''] ?? Payment::RECEIPT_TITLES[$purpose] ?? Payment::RECEIPT_TITLES[Payment::PURPOSE_SUBSCRIPTION],
            'description' => $this->describe($payment),
            'installation' => $this->installationFor($payment),
            'customer' => [
                'name' => $customer?->name,
                'phone' => $customer?->phone,
                'zone' => $customer?->zone?->name,
                'branch' => $customer?->zone?->branch?->name,
                'code' => $customer?->accountCode(),
            ],
            'payment' => [
                'uuid' => $payment->uuid,
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'method' => $payment->frequency,
                'frequency' => $payment->frequency,
                'months' => $payment->months,
                'periods' => $isSubscription ? $this->coveredPeriods($payment) : [],
                'expiration_date' => $payment->expiration_date?->toDateString(),
                'momo_ref' => $payment->verification?->momo_ref,
                'recorded_offline' => (bool) $payment->recorded_offline,
                'collected_at' => $payment->collected_at?->toIso8601String(),
                'recorded_at' => $payment->created_at?->toIso8601String(),
                'collected_by' => $payment->recordedBy?->name,
                // Cash controls: how the money came in (`method` above is
                // historically the frequency, kept for older layouts).
                'paid_by' => $payment->method ?? 'cash',
                'paid_by_label' => Payment::METHOD_LABELS[$payment->method ?? 'cash'] ?? 'Cash',
                'method_reference' => $payment->method_reference,
            ],
            // Written by the system, never by hand — see AmountInWords.
            'amount_in_words' => [
                'en' => AmountInWords::convert((string) $payment->amount),
                'fr' => AmountInWords::french((string) $payment->amount),
            ],
            // The subscription balance and "for bill" only mean something on
            // a subscription receipt; an installation receipt carries its own
            // fee/paid/balance block instead, a technician fee neither.
            'balance' => $isSubscription ? $this->balanceAfter($payment) : null,
            'invoice' => $isSubscription ? $this->invoiceFor($payment) : null,
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
     * The line printed under "Description" — the cable subscription, the
     * installation fee, or the technician's fee.
     */
    private function describe(Payment $payment): string
    {
        return match ($payment->purpose ?? Payment::PURPOSE_SUBSCRIPTION) {
            Payment::PURPOSE_INSTALLATION => match ($payment->customerCharge?->kind) {
                CustomerCharge::KIND_RELOCATION => 'Relocation fee',
                CustomerCharge::KIND_REINSTALLATION => 'Re-installation fee',
                default => 'Installation fee',
            },
            Payment::PURPOSE_TECHNICIAN_FEE => 'Technician motivation fee (reconnection)',
            default => 'Cable subscription',
        };
    }

    /**
     * An installation receipt's own balance: the fee, what has been paid
     * toward it so far (verified instalments, this one included) and what is
     * left. Null on every other receipt.
     *
     * @return array{fee: string, paid: string, balance: string, status: string}|null
     */
    private function installationFor(Payment $payment): ?array
    {
        if (($payment->purpose ?? null) !== Payment::PURPOSE_INSTALLATION || $payment->customer_charge_id === null) {
            return null;
        }

        $charge = $payment->customerCharge()->first();

        if ($charge === null) {
            return null;
        }

        // Relocation / re-installation: the fee block only — no free months
        // come with them, so no coverage lines.
        if ($charge->kind !== CustomerCharge::KIND_INSTALLATION) {
            return [
                'kind' => $charge->kind,
                'fee_label' => "{$charge->kindLabel()} fee",
                'fee' => bcadd((string) $charge->amount, '0', 2),
                'paid' => $charge->paidAmount(),
                'balance' => $charge->balance(),
                'status' => $charge->status,
                'services' => [],
                'waiting' => [],
                'first_bill' => null,
                'first_bill_label' => null,
            ];
        }

        // D11: no bill slips for free months — the free period, any advance
        // and the first bill month are printed on the installation receipt.
        $coverage = app(CustomerCoverageService::class)->summarize($charge->customer);

        return [
            'fee' => bcadd((string) $charge->amount, '0', 2),
            'paid' => $charge->paidAmount(),
            'balance' => $charge->balance(),
            'status' => $charge->status,
            // Each service's free and paid periods and its first billed month.
            'services' => array_values(array_map(fn (array $timeline): array => [
                'service' => $timeline['service'],
                'free' => $timeline['free_from'] !== null ? CustomerCoverageService::range($timeline['free_from'], (string) $timeline['free_until']) : null,
                'paid' => $timeline['paid_from'] !== null ? CustomerCoverageService::range($timeline['paid_from'], (string) $timeline['paid_until']) : null,
                'first_billed' => $timeline['first_billed'] !== null ? CustomerCoverageService::monthLabel($timeline['first_billed']) : null,
            ], array_filter($coverage['services'], fn (array $timeline): bool => $timeline['free_from'] !== null || $timeline['paid_from'] !== null))),
            'waiting' => $coverage['waiting'],
            'first_bill' => $coverage['first_bill'],
            'first_bill_label' => $coverage['first_bill'] !== null ? CustomerCoverageService::monthLabel($coverage['first_bill']) : null,
        ];
    }

    /**
     * What the customer still owes (or has in credit) once this payment is
     * counted: the amount due on their latest bill, minus every verified
     * subscription payment received since that bill was calculated (this
     * one included). Installation and technician-fee money never counts.
     * Null when the customer has never been billed. Frozen into the
     * snapshot like everything else on the receipt.
     *
     * @return array{remaining: string, credit: string, as_of_period: string}|null
     */
    private function balanceAfter(Payment $payment): ?array
    {
        $latest = $this->latestBill($payment);

        if ($latest === null) {
            return null;
        }

        $paidSince = (string) Payment::query()
            ->where('customer_id', $payment->customer_id)
            ->subscription()
            ->where('verification_status', 'verified')
            ->whereNull('processed_period')
            ->sum('amount');

        $balance = bcsub((string) $latest->total_bill, $paidSince === '' ? '0' : $paidSince, 2);
        $owes = bccomp($balance, '0', 2) > 0;

        return [
            'remaining' => $owes ? $balance : '0.00',
            'credit' => $owes ? '0.00' : bcmul($balance, '-1', 2),
            'as_of_period' => $latest->period,
        ];
    }

    /**
     * The bill (invoice) this payment goes toward — the customer's latest
     * bill when the receipt was issued. Null before their first bill.
     *
     * @return array{number: string, period: string}|null
     */
    private function invoiceFor(Payment $payment): ?array
    {
        $latest = $this->latestBill($payment);

        return $latest === null ? null : ['number' => $latest->billNumber(), 'period' => $latest->period];
    }

    private function latestBill(Payment $payment): ?Manuscript
    {
        // Bulk callers (e.g. reconnect-with-fine) already loaded it; it's
        // cached on the customer so balanceAfter()/invoiceFor() share it.
        if ($payment->customer !== null && ! $payment->customer->relationLoaded('latestManuscript')) {
            $payment->customer->setRelation('latestManuscript', Manuscript::query()
                ->where('customer_id', $payment->customer_id)
                ->orderByDesc('period')
                ->first());
        }

        return $payment->customer?->latestManuscript;
    }

    /**
     * The billing month(s) this payment pays for. A `monthly` payment covers
     * one month (its `processed_period` once consumed, else the month it was
     * collected/recorded in); a `months`/`yearly` prepayment covers that many
     * consecutive months from the collection month.
     *
     * @return list<string> YYYY-MM, chronological
     */
    private function coveredPeriods(Payment $payment): array
    {
        // Only subscription money pays for billing months.
        if (! $payment->isSubscription()) {
            return [];
        }

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
