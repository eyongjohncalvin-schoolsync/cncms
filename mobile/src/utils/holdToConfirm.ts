/**
 * Press-and-hold for big amounts (docs/plans/mobile-manager-app.md,
 * wave 6; the owner's chosen design B): a decision about this much money
 * or more needs the button held down, not tapped, so a slip of the thumb
 * can't approve it. Owner, 2026-11-01: 100 000 FCFA.
 */
export const HOLD_THRESHOLD_FCFA = 100_000;

/** How long the button must be held. */
export const HOLD_MS = 1500;

/** Whether a decision about this amount (either sign) needs a hold. */
export function needsHold(amount: number | string | null | undefined): boolean {
    if (amount === null || amount === undefined || amount === '') {
        return false;
    }

    const value = Math.abs(Number(amount));

    return Number.isFinite(value) && value >= HOLD_THRESHOLD_FCFA;
}
