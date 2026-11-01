/**
 * The app lock's rules (docs/plans/mobile-manager-app.md, wave 6) — kept
 * free of React Native so they are unit-tested. A phone that decides about
 * money is locked with a PIN (and the fingerprint, if the person wants):
 * on every open, and when it comes back after LOCK_AFTER_MS away.
 */

/** Away this long (in another app, screen off), the app asks again. */
export const LOCK_AFTER_MS = 60_000;

/** Wrong PINs in a row before the phone is signed out. */
export const MAX_PIN_ATTEMPTS = 5;

/**
 * 4 to 6 digits, and not one anybody guesses first: all the same digit,
 * or a straight run up or down (1234, 987654).
 */
export function pinProblem(pin: string): string | null {
    if (!/^\d{4,6}$/.test(pin)) {
        return 'Use 4 to 6 digits.';
    }

    if (/^(\d)\1+$/.test(pin)) {
        return 'Not the same digit over and over.';
    }

    const digits = pin.split('').map(Number);
    const steps = digits.slice(1).map((digit, index) => digit - digits[index]);

    if (steps.every((step) => step === 1) || steps.every((step) => step === -1)) {
        return 'Not a straight run like 1234.';
    }

    return null;
}

/** Back from the background: lock again when it was away long enough. */
export function shouldLockOnReturn(backgroundSince: number | null, now: number): boolean {
    return backgroundSince !== null && now - backgroundSince >= LOCK_AFTER_MS;
}

/** What to say after a wrong PIN; null once the attempts are used up. */
export function attemptsLeftMessage(failedAttempts: number): string | null {
    const left = MAX_PIN_ATTEMPTS - failedAttempts;

    if (left <= 0) {
        return null;
    }

    return left === 1 ? 'Wrong PIN. One more wrong PIN signs this phone out.' : `Wrong PIN. ${left} tries left.`;
}
