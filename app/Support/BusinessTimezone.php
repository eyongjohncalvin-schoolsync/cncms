<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * SWECOM operates in Kumba, Cameroon (WAT, UTC+1, no DST). App-wide
 * storage/comparison is UTC (config('app.timezone')), but a calendar
 * day/week/month is a period as experienced locally, not in UTC — a
 * payment recorded just after local midnight on the 1st must count toward
 * the new period, not fall into the last UTC hour of the prior one.
 *
 * Single shared source for this literal and for every "today" / "this
 * month" default (docs/plans/expenditures-and-spending-controls.md, item
 * 12): ResourcesDashboardService, ManuscriptRepository, ReportService,
 * CashBookService, VoucherService and the expenditure month close all go
 * through the helpers below, so the business day can never drift between
 * screens.
 */
final class BusinessTimezone
{
    public const string WAT = 'Africa/Lagos';

    /** The current moment on the Cameroon clock. */
    public static function now(): Carbon
    {
        return Carbon::now(self::WAT);
    }

    /** Today's date in Cameroon, `Y-m-d`. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** The current month in Cameroon, `Y-m`. */
    public static function currentPeriod(): string
    {
        return self::now()->format('Y-m');
    }

    /** The month before $period (`Y-m`). */
    public static function previousPeriod(?string $period = null): string
    {
        return self::periodStart($period ?? self::currentPeriod())->subMonthNoOverflow()->format('Y-m');
    }

    /** The `Y-m` month a date or instant falls in on the Cameroon clock. */
    public static function periodOf(Carbon|string $date): string
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date, self::WAT);

        return $date->copy()->setTimezone(self::WAT)->format('Y-m');
    }

    /** First day of $period (`Y-m`) at 00:00 WAT. */
    public static function periodStart(string $period): Carbon
    {
        // `!Y-m` resets the day to 01 in the parse itself; a bare 'Y-m'
        // keeps today's day-of-month and overflows a short month.
        return Carbon::createFromFormat('!Y-m', $period, self::WAT)->startOfMonth();
    }

    /**
     * UTC instant bounds of $period's calendar month in Cameroon — for
     * comparing `timestamptz` columns.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function periodBoundsUtc(string $period): array
    {
        return [
            self::periodStart($period)->setTimezone('UTC'),
            self::periodStart($period)->endOfMonth()->setTimezone('UTC'),
        ];
    }

    /**
     * First/last calendar date of $period, for plain DATE columns.
     *
     * @return array{0: string, 1: string}
     */
    public static function periodDates(string $period): array
    {
        return [self::periodStart($period)->toDateString(), self::periodStart($period)->endOfMonth()->toDateString()];
    }

    /**
     * UTC instant bounds of one Cameroon calendar day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayBoundsUtc(string $date): array
    {
        $start = Carbon::parse($date, self::WAT)->startOfDay();

        return [$start->copy()->setTimezone('UTC'), $start->copy()->endOfDay()->setTimezone('UTC')];
    }

    public static function isValidPeriod(?string $period): bool
    {
        return $period !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }
}
