<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes, on demand, which `active` customers have crossed the product
 * owner's arrears-based disconnection-eligibility threshold: accumulated
 * prior arrears (Manuscript::total_arrears — NOT including the current
 * cycle's fresh bill charge, see ManuscriptCalculator's ledger-model doc)
 * reaching 3x the customer's own monthly bill (Customer::bill), evaluated
 * only once the current period's payment deadline (business-rules.md
 * section 3: the 5th of the month) has passed.
 *
 * Deliberately fully computed at request time rather than a persisted
 * flag row: `customers` + `manuscripts` already carry everything this
 * needs, eligibility must stay in lockstep with the very next verified
 * payment or manuscript:calculate run (a persisted flag would go stale
 * the instant either changes underneath it), and nothing in this
 * feature's spec asks for dismissable "already contacted" state — adding
 * a table/column set for that now would be speculative. If a future
 * requirement needs office staff to suppress an already-actioned customer
 * from reappearing, App\Services\NotificationService is the mechanism to
 * build that on top of (e.g. a one-off App\Models\Notification per
 * flagged customer, acknowledged to suppress it) — the old dormant
 * App\Models\Alert this comment used to point to has since been retired
 * (see in-app-notifications.md section 7); until then this stays a pure
 * read.
 *
 * All money comparisons go through bccomp()/bcmul() on DECIMAL(12,2)
 * strings — never float `<`/`>`/`==` — matching ManuscriptCalculator's
 * hard convention. The only floats produced here (arrears_ratio) are
 * presentation-only ("2.8x bill" on the eligibility board) and are never
 * fed back into a threshold decision.
 */
final class CustomerEligibilityService
{
    /**
     * The product owner's rule, verbatim: 3x the customer's monthly bill.
     *
     * Public (not private) so App\Services\ReportService's monthly
     * collection-health arrears-aging buckets can reuse this exact value —
     * the report and this eligibility board must never be able to disagree
     * about where the "3x" line sits.
     */
    public const THRESHOLD_MULTIPLIER = '3';

    /**
     * business-rules.md section 3: "Bill print deadline is the 5th of
     * every month." Eligibility only triggers once the current calendar
     * period is actually past that due date — not the moment the 3x
     * threshold is crossed mid-cycle (e.g. by a manuscript:calculate run
     * landing on the 1st).
     */
    private const PAYMENT_DEADLINE_DAY = 5;

    private const SCALE = 2;

    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    /**
     * @param  ?int  $zoneId  Restrict to one zone (agent scoping / the
     *                        office zone filter), or null for every zone.
     * @return Collection<int, array<string, mixed>>
     */
    public function eligibleForDisconnection(?int $zoneId = null, ?Carbon $asOf = null): Collection
    {
        $asOf ??= Carbon::now();

        if (! $this->isPastPaymentDeadline($asOf)) {
            return new Collection;
        }

        return $this->customers->activeWithLatestManuscript($zoneId)
            ->filter(fn (Customer $customer): bool => $this->meetsThreshold($customer))
            ->map(fn (Customer $customer): array => $this->shape($customer))
            ->values();
    }

    private function isPastPaymentDeadline(Carbon $asOf): bool
    {
        return $asOf->day > self::PAYMENT_DEADLINE_DAY;
    }

    private function meetsThreshold(Customer $customer): bool
    {
        $manuscript = $customer->latestManuscript;

        if ($manuscript === null) {
            return false;
        }

        $bill = $this->normalize((string) $customer->bill);

        // A zero (or negative, which should never happen but bccomp
        // doesn't assume) monthly bill has no meaningful "3x" threshold —
        // treat as never eligible rather than dividing/flagging on 0.
        if (bccomp($bill, '0.00', self::SCALE) <= 0) {
            return false;
        }

        $threshold = bcmul($bill, self::THRESHOLD_MULTIPLIER, self::SCALE);
        $arrears = $this->normalize((string) $manuscript->total_arrears);

        return bccomp($arrears, $threshold, self::SCALE) >= 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Customer $customer): array
    {
        $manuscript = $customer->latestManuscript;
        $bill = $this->normalize((string) $customer->bill);
        $arrears = $this->normalize((string) $manuscript->total_arrears);

        return [
            'uuid' => $customer->uuid,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'zone_uuid' => $customer->zone?->uuid,
            'zone_name' => $customer->zone?->name,
            'bill' => $bill,
            'others' => (string) $customer->others,
            'level' => $customer->level,
            'status' => $customer->status,
            'status_reason' => $customer->status_reason,
            'status_note' => $customer->status_note,
            'location' => $customer->location,
            'total_arrears' => $arrears,
            // Display-only ("2.8x bill" on the board) — the eligibility
            // decision itself is entirely bccomp()-driven above; this
            // float never feeds back into a threshold check.
            'arrears_ratio' => round(((float) $arrears) / ((float) $bill), 2),
            'months_overdue' => (int) bcdiv($arrears, $bill, 0),
        ];
    }

    private function normalize(string $value): string
    {
        return bcadd($value, '0.00', self::SCALE);
    }
}
