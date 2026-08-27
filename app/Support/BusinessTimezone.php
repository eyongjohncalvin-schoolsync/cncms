<?php

declare(strict_types=1);

namespace App\Support;

/**
 * SWECOM operates in Kumba, Cameroon (WAT, UTC+1, no DST). App-wide
 * storage/comparison is UTC (config('app.timezone')), but a calendar
 * day/week/month is a period as experienced locally, not in UTC — a
 * payment recorded just after local midnight on the 1st must count toward
 * the new period, not fall into the last UTC hour of the prior one.
 *
 * Single shared source for this literal — previously duplicated as a
 * private `BUSINESS_TIMEZONE` constant on both
 * App\Services\ResourcesDashboardService and
 * App\Repositories\Eloquent\ManuscriptRepository. Both now reference this
 * constant instead, and App\Services\ReportService uses it too, so the
 * three places that build period bounds can never drift out of sync with
 * each other.
 */
final class BusinessTimezone
{
    public const string WAT = 'Africa/Lagos';
}
