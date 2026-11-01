/**
 * A counted amount typed on the phone's Cash screens (wave 4): whole FCFA,
 * spaces or dots between thousands allowed ("12 500", "12.500"). Returns
 * the plain number as a string for the API, or null when it isn't one.
 * Zero is a real count (an empty till), so it is accepted.
 */
export function parseCashAmount(text: string): string | null {
    const digits = text.replace(/[\s.,]/g, '');

    if (!/^\d{1,9}$/.test(digits)) {
        return null;
    }

    return String(Number(digits));
}

/** The difference a count makes against what the book expects, e.g. "−500" short or "+200" over. */
export function cashDifference(counted: string, expected: string): number {
    return Math.round(Number(counted)) - Math.round(Number(expected));
}
