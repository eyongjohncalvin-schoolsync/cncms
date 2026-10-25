<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one definition of a clean upper-case label (zone, branch and customer
 * names, customer locations): trimmed, inner whitespace collapsed to single
 * spaces, upper-cased with mb_strtoupper() so accents survive
 * ("molyko  é " -> "MOLYKO É"). Null or blank -> null.
 *
 * Models opt in through App\Models\Concerns\HasUpperCaseLabels, so every
 * Eloquent write is stored clean whichever path made it. Call this directly
 * where a value must be compared *before* it is stored: Form Requests'
 * prepareForValidation() (so max/unique judge the stored value), spreadsheet
 * imports, name lookups, and any bulk-create path.
 */
final class UpperCaseLabel
{
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $value)));

        return $value === '' ? null : $value;
    }
}
