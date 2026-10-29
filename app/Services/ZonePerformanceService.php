<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Scopes\BranchScope;
use App\Models\Zone;
use App\Models\ZoneAgent;
use App\Support\BusinessTimezone;
use App\Support\CacheVersion;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * How each zone collects (owner, 2026-10-29): its bills against what came
 * in, beside its usual — a zone that suddenly collects less is where money
 * taken without a receipt shows (no ledger ever sees it). Shared by the
 * Money balance page, the dashboard, the cash alerts and the agent's phone.
 *
 * Fair mid-month: in the running month every earlier month is counted only
 * up to the same day, so the 10th is compared with earlier 10ths. Two rates,
 * because one customer prepaying six months flatters the money rate: the
 * money collected against the bills, and the share of billed customers who
 * paid. A zone stands out when either falls DROP_POINTS below its usual.
 *
 * Cost: two grouped queries over TREND_MONTHS of bills and payments for one
 * branch — about 0.3 s at 10 000 customers (measured 2026-10-29) — cached per
 * branch, month and day for CACHE_MINUTES, or until a bill, customer or
 * agent changes (CacheVersion).
 */
class ZonePerformanceService
{
    public const int DROP_POINTS = 15;

    /** Earlier months that make a zone's usual. */
    public const int HISTORY_MONTHS = 3;

    /** Months on each zone's trend line, the chosen one included. */
    public const int TREND_MONTHS = 6;

    /** Before this day of the month too few payments are in to judge a zone. */
    public const int JUDGE_FROM_DAY = 5;

    public const int CACHE_MINUTES = 10;

    /**
     * Every zone of the current branch with bills or collections in $period.
     *
     * @return list<array{uuid: string, name: string, collector: ?string, billed: string, collected: string, rate: ?float, usual_rate: ?float,
     *     customers_billed: int, customers_paid: int, paid_share: ?float, usual_paid_share: ?float, dropped: bool, trend: list<array{period: string, rate: ?float}>}>
     */
    public function forPeriod(string $period): array
    {
        $branchId = TenantContext::currentBranchId();
        $day = $this->dayOf($period);
        // Not keyed on payments: they arrive every few seconds on a busy day,
        // which would leave nothing cached — the figures may be CACHE_MINUTES old.
        $key = 'zones:performance:'.CacheVersion::of(CacheVersion::MANUSCRIPTS, CacheVersion::CUSTOMERS, CacheVersion::AGENTS)
            .':'.($branchId ?? 'all').":{$period}:{$day}";

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), fn (): array => $this->compute($period, $day, $branchId));
    }

    /**
     * The zones that fell well below their usual — empty early in the month.
     *
     * @return list<array<string, mixed>>
     */
    public function dropped(string $period): array
    {
        if ($period === BusinessTimezone::currentPeriod() && BusinessTimezone::now()->day < self::JUDGE_FROM_DAY) {
            return [];
        }

        return array_values(array_filter($this->forPeriod($period), fn (array $zone): bool => $zone['dropped']));
    }

    /**
     * The running month counts up to today; a finished month counts whole.
     */
    public function dayOf(string $period): int
    {
        return $period === BusinessTimezone::currentPeriod() ? BusinessTimezone::now()->day : 31;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compute(string $period, int $day, ?int $branchId): array
    {
        $periods = [$period];

        while (count($periods) < self::TREND_MONTHS) {
            $periods[] = BusinessTimezone::previousPeriod(end($periods));
        }

        $bills = Manuscript::query()
            ->join('customers', 'customers.id', '=', 'manuscripts.customer_id')
            ->whereIn('manuscripts.period', $periods)
            ->when($branchId !== null, fn (Builder $query) => $query->where('manuscripts.branch_id', $branchId))
            ->selectRaw('customers.zone_id, manuscripts.period, sum(manuscripts.bill) as billed, count(*) filter (where manuscripts.bill > 0) as customers_billed')
            ->groupBy('customers.zone_id', 'manuscripts.period')->get()
            ->groupBy('zone_id')->map(fn (Collection $rows): Collection => $rows->keyBy('period'));

        [$from] = BusinessTimezone::periodBoundsUtc(end($periods));
        [, $to] = BusinessTimezone::periodBoundsUtc($period);
        $tz = BusinessTimezone::WAT;
        $byDay = "extract(day from payments.created_at at time zone '{$tz}') <= {$day}";

        // A correction's money is the original's; a rejected payment where no money changed hands is no money.
        $payments = Payment::query()->subscription()
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->whereBetween('payments.created_at', [$from, $to])
            ->whereNull('payments.paid_with_payment_id')
            ->where(fn (Builder $query) => $query->where('payments.verification_status', '!=', 'rejected')->orWhere('payments.money_taken', true))
            ->when($branchId !== null, fn (Builder $query) => $query->where('payments.branch_id', $branchId))
            ->selectRaw("customers.zone_id, to_char(payments.created_at at time zone '{$tz}', 'YYYY-MM') as period,
                sum(payments.amount) as collected, coalesce(sum(payments.amount) filter (where {$byDay}), 0) as collected_by_day,
                count(distinct payments.customer_id) as customers_paid, count(distinct payments.customer_id) filter (where {$byDay}) as customers_paid_by_day")
            ->groupByRaw('1, 2')->get()
            ->groupBy('zone_id')->map(fn (Collection $rows): Collection => $rows->keyBy('period'));

        $zones = Zone::query()->withoutGlobalScope(BranchScope::class)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('name')->get(['id', 'uuid', 'name']);

        $collectors = ZoneAgent::query()->withoutGlobalScope(BranchScope::class)->with('agent:id,name')
            ->where('role', ZoneAgent::ROLE_RESPONSIBLE)->whereIn('zone_id', $zones->pluck('id'))->get()
            ->mapWithKeys(fn (ZoneAgent $row): array => [$row->zone_id => $row->agent?->name]);

        $percent = fn (float $part, float $whole): ?float => $whole > 0 ? min(100.0, round(100 * $part / $whole, 1)) : null;
        $average = function (Collection $values): ?float {
            $known = $values->filter(fn (?float $value): bool => $value !== null);

            return $known->isEmpty() ? null : round($known->avg(), 1);
        };

        return $zones->map(function (Zone $zone) use ($bills, $payments, $collectors, $periods, $period, $percent, $average): array {
            $zoneBills = $bills->get($zone->id, collect());
            $zonePayments = $payments->get($zone->id, collect());

            // Rates at the same point of each month: up to today's day for every month in a running month.
            $rateOn = fn (string $month): ?float => $percent((float) ($zonePayments->get($month)?->collected_by_day ?? 0), (float) ($zoneBills->get($month)?->billed ?? 0));
            $shareOn = fn (string $month): ?float => $percent((float) ($zonePayments->get($month)?->customers_paid_by_day ?? 0), (float) ($zoneBills->get($month)?->customers_billed ?? 0));

            $earlier = collect(array_slice($periods, 1, self::HISTORY_MONTHS));
            $rate = $rateOn($period);
            $usualRate = $average($earlier->map($rateOn));
            $share = $shareOn($period);
            $usualShare = $average($earlier->map($shareOn));
            $fell = fn (?float $now, ?float $usual): bool => $now !== null && $usual !== null && $usual - $now >= self::DROP_POINTS;

            return [
                'uuid' => $zone->uuid,
                'name' => $zone->name,
                'collector' => $collectors->get($zone->id),
                'billed' => $this->money($zoneBills->get($period)?->billed),
                'collected' => $this->money($zonePayments->get($period)?->collected_by_day),
                'rate' => $rate,
                'usual_rate' => $usualRate,
                'customers_billed' => (int) ($zoneBills->get($period)?->customers_billed ?? 0),
                'customers_paid' => (int) ($zonePayments->get($period)?->customers_paid_by_day ?? 0),
                'paid_share' => $share,
                'usual_paid_share' => $usualShare,
                'dropped' => $fell($rate, $usualRate) || $fell($share, $usualShare),
                // Whole months, oldest first; the chosen month so far.
                'trend' => collect(array_reverse($periods))->map(fn (string $month): array => [
                    'period' => $month,
                    'rate' => $percent((float) ($zonePayments->get($month)?->collected ?? 0), (float) ($zoneBills->get($month)?->billed ?? 0)),
                ])->all(),
            ];
        })
            ->filter(fn (array $zone): bool => bccomp($zone['billed'], '0', 2) > 0 || bccomp($zone['collected'], '0', 2) > 0)
            ->values()->all();
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
