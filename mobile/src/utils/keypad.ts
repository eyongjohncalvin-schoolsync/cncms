/**
 * Pure state logic behind src/components/ui/NumericKeypad.tsx — kept out of
 * the component so it can be unit tested without React Native (see
 * src/utils/__tests__/keypad.test.ts). The keypad's value is always a
 * plain digit string with no leading zeros ('' means nothing typed yet).
 */

export type KeypadKey = '0' | '1' | '2' | '3' | '4' | '5' | '6' | '7' | '8' | '9' | '000' | 'back' | 'clear';

/** 9 digits = up to 999 999 999 FCFA — far above any real field expense,
 * and stays well inside a safe integer. */
export const KEYPAD_MAX_DIGITS = 9;

export function applyKeypadKey(value: string, key: KeypadKey, maxDigits: number = KEYPAD_MAX_DIGITS): string {
    if (key === 'clear') {
        return '';
    }

    if (key === 'back') {
        return value.slice(0, -1);
    }

    // Zeros typed onto an empty amount mean nothing — never show "000".
    if (value === '' && (key === '0' || key === '000')) {
        return '';
    }

    const next = `${value}${key}`;

    if (next.length > maxDigits) {
        return value;
    }

    return next;
}

/** "12500" -> "12 500". Space-grouped, the way amounts are written on
 * receipts and MoMo SMS in Cameroon. */
export function formatGroupedAmount(digits: string | number): string {
    const raw = typeof digits === 'number' ? String(Math.round(digits)) : digits;

    if (raw === '') {
        return '0';
    }

    return raw.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

/** Digit string back to a number (0 for ''). */
export function keypadValueToNumber(value: string): number {
    if (value === '') {
        return 0;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

/** A stored amount (e.g. 12500.00 for "Repeat last") as keypad digits. */
export function numberToKeypadValue(amount: number): string {
    if (!Number.isFinite(amount) || amount <= 0) {
        return '';
    }

    return String(Math.round(amount));
}
