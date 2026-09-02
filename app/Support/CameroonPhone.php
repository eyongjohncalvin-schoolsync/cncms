<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical Cameroon-mobile phone normaliser for the wa.me deep links used
 * by the manual (free, no-Twilio) WhatsApp flows — bill reminders
 * (App\Services\BillNotificationService) and payment receipts
 * (App\Services\ReceiptWhatsAppService). Both delegate here so the rule
 * lives in exactly one place.
 *
 * Real customer data is messy — '677440670', '(67) 321-7927',
 * '+237 677 44 06 70', '00237677440670' (see database-schema.md's known
 * data-quality issues). Cameroon mobile numbers are 9 local digits; this
 * returns the digits-only, '237'-prefixed international form wa.me wants
 * (no '+', no '00'), or null when the shape doesn't match — a wrong number
 * is worse than no link, and ~78% of legacy customers have no usable phone
 * at all, so null is an expected, common, non-error outcome.
 *
 * The client mirror is mobile/src/utils/whatsapp.ts
 * (normalizeCameroonPhoneForWhatsapp) — keep the two in lockstep.
 */
final class CameroonPhone
{
    public static function forWhatsapp(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        // '00237…' international-access prefix → drop the leading '00'.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '237') && strlen($digits) === 12) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 9 ? '237'.$digits : null;
    }
}
