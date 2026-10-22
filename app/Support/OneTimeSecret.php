<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Random one-time secrets for account security (email codes, login
 * challenge tokens, recovery codes) and the keyed hash they are stored as.
 * HMAC-SHA256 with the app key: a leaked database alone cannot be used to
 * look codes up, and the lookup is still a direct equality match.
 */
final class OneTimeSecret
{
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    public static function matches(string $hash, string $value): bool
    {
        return hash_equals($hash, self::hash($value));
    }

    /** A 6-digit numeric code, e.g. "042917". */
    public static function numericCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    /** An opaque 64-character token. */
    public static function token(): string
    {
        return Str::random(64);
    }

    /** A recovery code such as "K7Q2M-X9DPA" (no 0/O/1/I to misread). */
    public static function recoveryCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 5).'-'.substr($code, 5);
    }

    /** Recovery codes are matched case- and dash-insensitively. */
    public static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
