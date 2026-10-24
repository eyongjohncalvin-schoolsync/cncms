/**
 * Writes a whole FCFA amount out in English words — shown under the big
 * keypad figure on Record Expense so a mistyped zero ("12 500" vs
 * "125 000") is caught by reading, not by squinting at digits. British
 * style ("one hundred and five"), which is what Cameroonian English uses on
 * cheques and receipts. Pure and dependency-free so it can be unit tested
 * directly (src/utils/__tests__/amountInWords.test.ts).
 */

const ONES = [
    'zero',
    'one',
    'two',
    'three',
    'four',
    'five',
    'six',
    'seven',
    'eight',
    'nine',
    'ten',
    'eleven',
    'twelve',
    'thirteen',
    'fourteen',
    'fifteen',
    'sixteen',
    'seventeen',
    'eighteen',
    'nineteen',
];

const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

const SCALES = ['', 'thousand', 'million', 'billion', 'trillion'];

/** 1–99 in words ("forty-two"). */
function underHundred(n: number): string {
    if (n < 20) {
        return ONES[n];
    }

    const tens = TENS[Math.floor(n / 10)];
    const unit = n % 10;

    return unit === 0 ? tens : `${tens}-${ONES[unit]}`;
}

/** 1–999 in words ("three hundred and five"). */
function underThousand(n: number): string {
    const hundreds = Math.floor(n / 100);
    const rest = n % 100;

    if (hundreds === 0) {
        return underHundred(rest);
    }

    if (rest === 0) {
        return `${ONES[hundreds]} hundred`;
    }

    return `${ONES[hundreds]} hundred and ${underHundred(rest)}`;
}

/**
 * Lower-case words for a non-negative whole number ("twelve thousand five
 * hundred"). Fractions are rounded; negatives and non-finite values read as
 * an empty string (the keypad never produces them).
 */
export function numberToWords(value: number): string {
    if (!Number.isFinite(value) || value < 0) {
        return '';
    }

    let n = Math.round(value);

    if (n === 0) {
        return ONES[0];
    }

    const chunks: number[] = [];

    while (n > 0) {
        chunks.push(n % 1000);
        n = Math.floor(n / 1000);
    }

    if (chunks.length > SCALES.length) {
        return '';
    }

    const parts: string[] = [];

    for (let index = chunks.length - 1; index >= 0; index -= 1) {
        const chunk = chunks[index];

        if (chunk === 0) {
            continue;
        }

        const words = underThousand(chunk);
        const scale = SCALES[index];

        // "two thousand and five" — the British "and" before a trailing
        // sub-hundred chunk when something bigger came before it.
        if (index === 0 && chunk < 100 && parts.length > 0) {
            parts.push(`and ${words}`);
        } else {
            parts.push(scale ? `${words} ${scale}` : words);
        }
    }

    return parts.join(' ');
}

/** "Twelve thousand five hundred francs CFA" — capitalised, with the
 * currency; empty for 0 so the keypad shows nothing under an empty amount. */
export function amountInWords(amount: number): string {
    if (!Number.isFinite(amount) || amount <= 0) {
        return '';
    }

    const words = numberToWords(amount);

    if (!words) {
        return '';
    }

    const currency = Math.round(amount) === 1 ? 'franc CFA' : 'francs CFA';

    return `${words.charAt(0).toUpperCase()}${words.slice(1)} ${currency}`;
}
