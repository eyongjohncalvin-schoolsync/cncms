import { formatMonthYear } from './formatMonthYear';

/**
 * The "Expiry" / "covered through" label for a manuscript row.
 *
 * Legacy prepaid customers carry a `payment_expiration` date; draw-down
 * customers (references/prepayment-drawdown.md) carry a
 * `prepaid_months_remaining` counter instead, from which the covered-through
 * month is derived (`period` + N months). Falls back to "—".
 */
export function prepaidCoverageLabel(m: {
    payment_expiration: string | null;
    prepaid_months_remaining?: number | null;
    period: string;
}): string {
    if (m.payment_expiration) {
        return formatMonthYear(m.payment_expiration);
    }

    const remaining = m.prepaid_months_remaining ?? 0;
    if (remaining > 0) {
        const [y, mo] = m.period.split('-').map(Number);
        if (Number.isFinite(y) && Number.isFinite(mo)) {
            const d = new Date(Date.UTC(y, mo - 1 + remaining, 1));
            return d.toLocaleDateString('en-US', { month: 'short', year: '2-digit', timeZone: 'UTC' });
        }
    }

    return '—';
}
