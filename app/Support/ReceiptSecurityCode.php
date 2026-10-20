<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The 8-character security code printed on every receipt
 * (docs/plans/cash-controls.md): Crockford base32 of the first 40 bits of
 * HMAC-SHA256(key, "{number}|{customer_uuid}|{amount}|{issued_at_unix}").
 *
 * Changing any of those four facts — the amount above all — produces a
 * different code, and nobody can produce a valid code without the key:
 *   - office receipts use a per-tenant key derived from APP_KEY (officeKey);
 *   - a field device signs with its own secret (FieldDevice::secret), so a
 *     phone can issue authenticated receipts while offline. The mobile app
 *     implements the identical algorithm (mobile/src/utils/receiptCode.ts);
 *     the canonical string and the alphabet must never drift between them.
 *
 * 40 bits (~1 in a trillion per guess) is ample for a code checked by a
 * throttled public page; the receipt number itself is not a secret.
 */
final class ReceiptSecurityCode
{
    /** Crockford base32 — no I, L, O or U, so a hand-copied code is unambiguous. */
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function canonical(string $receiptNumber, string $customerUuid, string|float|int $amount, int $issuedAtUnix): string
    {
        return implode('|', [
            strtoupper($receiptNumber),
            strtolower($customerUuid),
            number_format((float) $amount, 2, '.', ''),
            $issuedAtUnix,
        ]);
    }

    /**
     * @param  string  $key  raw key bytes
     */
    public static function compute(string $key, string $canonical): string
    {
        $digest = hash_hmac('sha256', $canonical, $key, true);

        // First 5 bytes = 40 bits = 8 base32 characters.
        $bits = '';
        foreach (str_split(substr($digest, 0, 5)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $code = '';
        foreach (str_split($bits, 5) as $chunk) {
            $code .= self::ALPHABET[bindec($chunk)];
        }

        return $code;
    }

    /** "K7QF2MXA" → "K7QF-2MXA" for printing. */
    public static function format(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * What a customer typed or a QR carried → the bare 8-character code:
     * uppercased, separators dropped, and the look-alikes Crockford maps
     * (O→0, I/L→1) folded in.
     */
    public static function normalize(?string $input): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $input) ?? '');

        return strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    public static function matches(?string $expected, ?string $given): bool
    {
        if ($expected === null || $expected === '') {
            return false;
        }

        return hash_equals(self::normalize($expected), self::normalize($given));
    }

    /**
     * The per-tenant key for receipts the server itself issues. Derived, not
     * stored: rotating APP_KEY would change office codes for receipts issued
     * afterwards only — codes are frozen on the receipt at issue time.
     */
    public static function officeKey(string $tenantKey): string
    {
        $appKey = (string) config('app.key');

        if (Str::startsWith($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }

        return hash_hmac('sha256', 'cncms-receipt-codes|'.$tenantKey, $appKey, true);
    }

    /** A new field device's secret — 32 random bytes, hex-encoded for the phone. */
    public static function newDeviceSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** A device secret (hex) → raw key bytes for compute(). */
    public static function deviceKey(string $hexSecret): string
    {
        return (string) hex2bin($hexSecret);
    }
}
