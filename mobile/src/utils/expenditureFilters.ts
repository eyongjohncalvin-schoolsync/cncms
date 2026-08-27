import { toDateOnly } from './format';

/**
 * Period-total + category-filter logic for the Resources screen (this
 * device's own recorded expenditures) — kept as pure functions, separate
 * from the screen component, so they're directly unit-testable, matching
 * the paymentFilters.ts convention (see
 * src/utils/__tests__/paymentFilters.test.ts).
 */
export type ExpenditurePeriod = 'today' | 'week' | 'month';

export const EXPENDITURE_PERIODS: ExpenditurePeriod[] = ['today', 'week', 'month'];

export function periodLabel(period: ExpenditurePeriod): string {
    switch (period) {
        case 'today':
            return 'Today';
        case 'week':
            return 'This week';
        case 'month':
            return 'This month';
        default:
            return period;
    }
}

/**
 * Inclusive start date (`YYYY-MM-DD`) for a period, anchored on `today`
 * (defaults to now, overridable for tests). "This week" is a simple rolling
 * 7-day window (today minus 6 days) rather than a calendar-week start, to
 * avoid any locale-specific "week starts on Sunday/Monday" assumption.
 * "This month" is the calendar month containing `today`.
 */
export function periodStartDate(period: ExpenditurePeriod, today: Date = new Date()): string {
    if (period === 'today') {
        return toDateOnly(today);
    }

    if (period === 'week') {
        const start = new Date(today);
        start.setDate(start.getDate() - 6);

        return toDateOnly(start);
    }

    return toDateOnly(new Date(today.getFullYear(), today.getMonth(), 1));
}

export function filterExpendituresByCategory<T extends { category_uuid: string }>(
    expenditures: T[],
    categoryUuid: string | null,
): T[] {
    if (!categoryUuid) {
        return expenditures;
    }

    return expenditures.filter((expenditure) => expenditure.category_uuid === categoryUuid);
}
