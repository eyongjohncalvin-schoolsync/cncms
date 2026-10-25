/**
 * How a multi-month / yearly prepayment splits, as the bill run applies it
 * (App\Services\ManuscriptCalculator): old debt is paid off first, the rest
 * buys whole months at the customer's bill (up to the months asked for),
 * and anything left over is credit. Shown on Record Payment so the agent
 * can tell the customer exactly what their money buys.
 */
export interface PrepaymentSplit {
    debtCleared: number;
    months: number;
    credit: number;
}

export function splitPrepayment(amount: number, debt: number, bill: number, monthsAsked: number | null): PrepaymentSplit {
    const debtCleared = Math.min(Math.max(debt, 0), Math.max(amount, 0));
    const rest = Math.max(amount, 0) - debtCleared;

    if (bill <= 0) {
        return { debtCleared, months: 0, credit: rest };
    }

    const affordable = Math.floor(rest / bill);
    const months = monthsAsked !== null && monthsAsked > 0 ? Math.min(affordable, monthsAsked) : affordable;

    return { debtCleared, months, credit: rest - months * bill };
}
