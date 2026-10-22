<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mints the numbers printed on cash documents (docs/plans/cash-controls.md):
 * `{SERIES}-{YYYY}-{6-digit}` from the tenant's `document_counters` table —
 * the same row-locked scheme PaymentReceiptService::allocateNumber() uses
 * for receipt numbers, generalised to one counter per (series, year).
 *
 * MUST be called inside the caller's transaction: the FOR UPDATE lock is
 * what serialises two concurrent allocations. Gap-tolerant — a rolled-back
 * transaction simply skips its number.
 */
class DocumentNumberService
{
    /** Receipt books (a printed or digital range of receipt numbers). */
    public const string RECEIPT_BLOCK = 'RB';

    /** Daily remittances — the collector's cash handover. */
    public const string REMITTANCE = 'RM';

    /** Vouchers / bons de caisse. */
    public const string VOUCHER = 'BC';

    /** Deposits from a till into the bank / safe. */
    public const string DEPOSIT = 'DP';

    /** Transfers between company cash accounts. */
    public const string TRANSFER = 'TR';

    /** Technician motivation fees paid out by the cashier (docs/plans/installation-and-promotions.md, D13). */
    public const string TECHNICIAN_FEE = 'TF';

    /** @var list<string> */
    public const array SERIES = [self::RECEIPT_BLOCK, self::REMITTANCE, self::VOUCHER, self::DEPOSIT, self::TRANSFER, self::TECHNICIAN_FEE];

    public function next(string $series): string
    {
        if (! in_array($series, self::SERIES, true)) {
            throw new InvalidArgumentException("Unknown document series [{$series}].");
        }

        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException('Document numbers must be allocated inside a transaction.');
        }

        $year = (int) now()->format('Y');

        DB::table('document_counters')->insertOrIgnore([
            'series' => $series,
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('document_counters')->where('series', $series)->where('year', $year)->lockForUpdate()->first();
        $number = (int) ($row->next_number ?? 1);

        DB::table('document_counters')
            ->where('series', $series)
            ->where('year', $year)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return sprintf('%s-%04d-%06d', $series, $year, $number);
    }
}
