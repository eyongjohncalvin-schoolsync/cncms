import type { ExpenditureSource } from '@/types';

/** How each expenditure source reads on screen (docs/plans/expenditures-and-spending-controls.md). */
export const SOURCE_LABELS: Record<ExpenditureSource, string> = { voucher: 'Bon', legacy: 'Legacy', adjustment: 'Correction' };

/** `2026-10` → "October 2026". */
export function monthLabel(month: string): string {
    const [year, monthIndex] = month.split('-').map(Number);

    return new Date(year, monthIndex - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}
