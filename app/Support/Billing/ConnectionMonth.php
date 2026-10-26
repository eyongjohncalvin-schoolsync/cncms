<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Support\BusinessTimezone;
use Illuminate\Support\Carbon;

/**
 * The months a new customer's connection date decides (owner, 2026-10-26) —
 * the one place this rule lives on the server (the forms mirror it:
 * resources/tsx/components/customers/InstallationSection.tsx and the
 * phone's src/utils/installation.ts):
 *
 *  - of(): the month they were connected — free months count from here, even
 *    when they are registered late and that month has closed;
 *  - billingStarts(): the first month their ledger may be written — the
 *    connection month, or the current month when that has passed (closed
 *    months are never rewritten, so months between the end of the free
 *    months and now are simply not billed unless an admin charges them).
 */
final class ConnectionMonth
{
    public static function of(string $connectedOn): string
    {
        return BusinessTimezone::periodOf(Carbon::parse($connectedOn, BusinessTimezone::WAT));
    }

    public static function billingStarts(string $connectedOn, string $currentPeriod): string
    {
        return max(self::of($connectedOn), $currentPeriod);
    }

    /** Registered after the month they were connected in had closed. */
    public static function isLate(string $connectedOn, string $currentPeriod): bool
    {
        return self::of($connectedOn) < $currentPeriod;
    }
}
