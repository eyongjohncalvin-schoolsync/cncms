/**
 * One zone's month — App\Services\ZonePerformanceService::forPeriod(). Shared
 * by the Money balance page and the dashboard's zone card.
 */
export interface ZonePerformance {
    uuid: string;
    name: string;
    collector: string | null;
    billed: string;
    collected: string;
    /** Money collected against the bills, %. */
    rate: number | null;
    usual_rate: number | null;
    customers_billed: number;
    customers_paid: number;
    /** Billed customers who paid, % — one prepayment can't flatter it. */
    paid_share: number | null;
    usual_paid_share: number | null;
    dropped: boolean;
    trend?: { period: string; rate: number | null }[];
}

/** ZonePerformanceService::DROP_POINTS. */
export const DROP_POINTS = 15;

/** A rate beside its usual, red when it fell well below. */
export function Rate({ now, usual }: { now: number | null; usual: number | null }) {
    const fell = now !== null && usual !== null && usual - now >= DROP_POINTS;

    return (
        <span>
            <span className={fell ? 'font-semibold text-red-700' : 'text-slate-900'}>{now === null ? '—' : `${now}%`}</span>
            <span className="ml-1 text-xs text-slate-500">usual {usual === null ? '—' : `${usual}%`}</span>
        </span>
    );
}

/** Six small bars, oldest first: each month's money collected against its bills (the last one so far). */
export function ZoneTrend({ trend }: { trend?: ZonePerformance['trend'] }) {
    if (!trend || trend.length === 0) {
        return null;
    }

    return (
        <div className="flex h-8 items-end gap-0.5" aria-label="Collection rate, last six months">
            {trend.map((month, index) => (
                <div
                    key={month.period}
                    title={`${month.period}: ${month.rate === null ? 'no bills' : `${month.rate}%`}`}
                    className={`w-2 rounded-sm ${index === trend.length - 1 ? 'bg-blue-600' : 'bg-slate-300'}`}
                    style={{ height: `${Math.max(2, Math.min(100, month.rate ?? 0))}%` }}
                />
            ))}
        </div>
    );
}
