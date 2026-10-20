/**
 * Amount in words for the on-screen / printed field receipt — the phone's
 * copy of App\Support\AmountInWords (convert() + french()), so a receipt
 * issued offline reads exactly like one the server prints. The collector
 * never writes the amount in words by hand.
 */

const EN_ONES = [
    '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
    'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
];
const EN_TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

const FR_ONES = [
    'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize',
    'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf',
];
const FR_TENS: Record<number, string> = { 2: 'vingt', 3: 'trente', 4: 'quarante', 5: 'cinquante', 6: 'soixante' };

function spellEnglish(n: number): string {
    if (n === 0) {
        return '';
    }

    if (n < 20) {
        return EN_ONES[n];
    }

    if (n < 100) {
        const rest = spellEnglish(n % 10);

        return EN_TENS[Math.floor(n / 10)] + (rest !== '' ? `-${rest.toLowerCase()}` : '');
    }

    const units: Array<[number, string]> = [
        [1_000_000_000, 'Billion'],
        [1_000_000, 'Million'],
        [1_000, 'Thousand'],
        [100, 'Hundred'],
    ];

    for (const [value, label] of units) {
        if (n >= value) {
            const remainder = n % value;

            return `${spellEnglish(Math.floor(n / value))} ${label}${remainder > 0 ? ` ${spellEnglish(remainder)}` : ''}`.trim();
        }
    }

    return '';
}

function spellFrench(n: number, final = true): string {
    if (n < 20) {
        return FR_ONES[n];
    }

    if (n < 100) {
        const tens = Math.floor(n / 10);
        const unit = n % 10;

        if (tens <= 6) {
            return unit === 0 ? FR_TENS[tens] : FR_TENS[tens] + (unit === 1 ? ' et un' : `-${FR_ONES[unit]}`);
        }

        if (tens === 7) {
            return `soixante${unit === 1 ? ' et onze' : `-${FR_ONES[10 + unit]}`}`;
        }

        const rest = n - 80;

        return rest === 0 ? `quatre-vingt${final ? 's' : ''}` : `quatre-vingt-${FR_ONES[rest]}`;
    }

    if (n < 1000) {
        const hundreds = Math.floor(n / 100);
        const rest = n % 100;
        const head = hundreds === 1 ? 'cent' : `${FR_ONES[hundreds]} cent`;

        if (rest === 0) {
            return head + (hundreds > 1 && final ? 's' : '');
        }

        return `${head} ${spellFrench(rest, final)}`;
    }

    if (n < 1_000_000) {
        const thousands = Math.floor(n / 1000);
        const rest = n % 1000;
        const head = thousands === 1 ? 'mille' : `${spellFrench(thousands, false)} mille`;

        return head + (rest > 0 ? ` ${spellFrench(rest, final)}` : '');
    }

    const units: Array<[number, string]> = [
        [1_000_000_000, 'milliard'],
        [1_000_000, 'million'],
    ];

    for (const [value, label] of units) {
        if (n >= value) {
            const count = Math.floor(n / value);
            const rest = n % value;

            return `${spellFrench(count)} ${label}${count > 1 ? 's' : ''}${rest > 0 ? ` ${spellFrench(rest, final)}` : ''}`;
        }
    }

    return String(n);
}

/** "Ten Thousand FCFA Only" — AmountInWords::convert(). */
export function amountInEnglish(amount: number): string {
    const whole = Math.round(amount);

    if (whole === 0) {
        return 'Zero FCFA Only';
    }

    return `${whole < 0 ? 'Minus ' : ''}${spellEnglish(Math.abs(whole)).trim()} FCFA Only`;
}

/** "Dix mille francs CFA" — AmountInWords::french(). */
export function amountInFrench(amount: number): string {
    const whole = Math.round(amount);

    if (whole < 0) {
        return `Moins ${spellFrench(-whole)} francs CFA`;
    }

    const words = spellFrench(whole);

    return words.charAt(0).toUpperCase() + words.slice(1) + (whole <= 1 ? ' franc CFA' : ' francs CFA');
}
