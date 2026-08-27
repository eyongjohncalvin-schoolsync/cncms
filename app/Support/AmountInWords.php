<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spells out an FCFA amount for the "Classic Ledger" bill template's
 * amount-in-words line (e.g. "Four Thousand FCFA Only") — a plain PHP
 * implementation rather than PHP's intl NumberFormatter(::SPELLOUT), since
 * ext-intl isn't a confirmed dependency of this codebase (composer.lock
 * only lists it as an optional "suggest" for several packages, not a
 * required extension) and FCFA has no minor/decimal unit worth spelling
 * out — every bill amount is effectively a whole number of francs.
 */
final class AmountInWords
{
    /** @var array<int, string> */
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    /** @var array<int, string> */
    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    public static function convert(string|float|int $amount): string
    {
        $whole = (int) round((float) $amount);

        if ($whole === 0) {
            return 'Zero FCFA Only';
        }

        $negative = $whole < 0;
        $whole = abs($whole);

        $words = self::spellInteger($whole);

        return ($negative ? 'Minus ' : '').trim($words).' FCFA Only';
    }

    private static function spellInteger(int $number): string
    {
        if ($number === 0) {
            return '';
        }

        if ($number < 20) {
            return self::ONES[$number];
        }

        if ($number < 100) {
            $tens = self::TENS[intdiv($number, 10)];
            $rest = self::spellInteger($number % 10);

            return trim($tens.($rest !== '' ? '-'.strtolower($rest) : ''));
        }

        $units = [
            1_000_000_000 => 'Billion',
            1_000_000 => 'Million',
            1_000 => 'Thousand',
            100 => 'Hundred',
        ];

        foreach ($units as $value => $label) {
            if ($number >= $value) {
                $count = intdiv($number, $value);
                $remainder = $number % $value;

                $head = self::spellInteger($count).' '.$label;
                $tail = $remainder > 0 ? ' '.self::spellInteger($remainder) : '';

                return trim($head.$tail);
            }
        }

        return '';
    }
}
