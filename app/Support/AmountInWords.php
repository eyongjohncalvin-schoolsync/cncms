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

    /**
     * The French counterpart of convert() for receipts and vouchers
     * (docs/plans/cash-controls.md), e.g. "Dix mille francs CFA". Traditional
     * spelling as used on Cameroonian cheques: "et" before un/onze below 80,
     * plural "s" on quatre-vingts and cents only when nothing follows,
     * "mille" invariable (never "un mille"), "million(s)"/"milliard(s)".
     */
    public static function french(string|float|int $amount): string
    {
        $whole = (int) round((float) $amount);

        if ($whole < 0) {
            return 'Moins '.self::spellFrench(-$whole).' francs CFA';
        }

        // "franc" stays singular for zero and one ("Un franc CFA").
        return ucfirst(self::spellFrench($whole)).($whole <= 1 ? ' franc CFA' : ' francs CFA');
    }

    /** @var array<int, string> */
    private const FR_ONES = [
        'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
        'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf',
    ];

    /** @var array<int, string> */
    private const FR_TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    /**
     * $final: nothing follows this number in the phrase — only then do
     * "quatre-vingts" and "deux cents" take their plural s.
     */
    private static function spellFrench(int $number, bool $final = true): string
    {
        if ($number < 20) {
            return self::FR_ONES[$number];
        }

        if ($number < 100) {
            $tens = intdiv($number, 10);
            $unit = $number % 10;

            if ($tens <= 6) {
                return $unit === 0 ? self::FR_TENS[$tens] : self::FR_TENS[$tens].($unit === 1 ? ' et un' : '-'.self::FR_ONES[$unit]);
            }

            if ($tens === 7) {
                return 'soixante'.($unit === 1 ? ' et onze' : '-'.self::FR_ONES[10 + $unit]);
            }

            $rest = $number - 80;

            return $rest === 0 ? 'quatre-vingt'.($final ? 's' : '') : 'quatre-vingt-'.self::FR_ONES[$rest];
        }

        if ($number < 1000) {
            $hundreds = intdiv($number, 100);
            $rest = $number % 100;
            $head = $hundreds === 1 ? 'cent' : self::FR_ONES[$hundreds].' cent';

            if ($rest === 0) {
                return $head.($hundreds > 1 && $final ? 's' : '');
            }

            return $head.' '.self::spellFrench($rest, $final);
        }

        if ($number < 1_000_000) {
            $thousands = intdiv($number, 1000);
            $rest = $number % 1000;
            $head = $thousands === 1 ? 'mille' : self::spellFrench($thousands, false).' mille';

            return $head.($rest > 0 ? ' '.self::spellFrench($rest, $final) : '');
        }

        foreach ([1_000_000_000 => 'milliard', 1_000_000 => 'million'] as $value => $label) {
            if ($number >= $value) {
                $count = intdiv($number, $value);
                $rest = $number % $value;

                return self::spellFrench($count).' '.$label.($count > 1 ? 's' : '').($rest > 0 ? ' '.self::spellFrench($rest, $final) : '');
            }
        }

        return (string) $number;
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
