/** FCFA has no minor unit in practice for this app's amounts — matches the
 * web app's whole-number display convention. */
export function formatFcfa(amount: number): string {
    return `${Math.round(amount).toLocaleString('en-US')} FCFA`;
}

/** Start-of-local-day ISO boundary, used for "today's collection total". */
export function startOfTodayIso(): string {
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    return start.toISOString();
}

export function nowIso(): string {
    return new Date().toISOString();
}

/** Local (not UTC) calendar date as YYYY-MM-DD — used for the `spent_at`
 * DATE column (database/migrations/tenant/..._create_expenditures_table.php
 * confirms `spent_at` is a plain DATE, not a timestamp), so this
 * deliberately uses local Y/M/D rather than `toISOString()`'s UTC slice,
 * which can roll to the wrong calendar day near midnight. */
export function toDateOnly(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export function todayDateOnly(): string {
    return toDateOnly(new Date());
}

export function yesterdayDateOnly(): string {
    const date = new Date();
    date.setDate(date.getDate() - 1);

    return toDateOnly(date);
}

/** "2 min ago" / "Just now" / "3 days ago" style relative time for sync
 * timestamps. Deliberately hand-rolled rather than pulling in date-fns/dayjs
 * for one formatter — see mobile-app-react-native.md §1's "copy the ~15
 * interfaces by hand, don't stand up more machinery than the surface needs"
 * philosophy, applied here to a one-function date-math need. */
export function formatRelativeTime(iso: string | null, now: Date = new Date()): string {
    if (!iso) {
        return 'Never synced';
    }

    const then = new Date(iso).getTime();

    if (Number.isNaN(then)) {
        return 'Never synced';
    }

    const diffMs = now.getTime() - then;
    const diffSec = Math.round(diffMs / 1000);

    if (diffSec < 5) {
        return 'Just now';
    }

    if (diffSec < 60) {
        return `${diffSec} sec ago`;
    }

    const diffMin = Math.round(diffSec / 60);

    if (diffMin < 60) {
        return `${diffMin} min ago`;
    }

    const diffHour = Math.round(diffMin / 60);

    if (diffHour < 24) {
        return `${diffHour} hr${diffHour === 1 ? '' : 's'} ago`;
    }

    const diffDay = Math.round(diffHour / 24);

    return `${diffDay} day${diffDay === 1 ? '' : 's'} ago`;
}

/** Short human date for list rows, e.g. "23 Aug 2026". */
export function formatShortDate(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
