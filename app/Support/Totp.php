<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238) as used by authenticator apps
 * (Google Authenticator, Microsoft Authenticator, Authy, ...): HMAC-SHA1,
 * 30-second steps, 6 digits. Secrets are exchanged as RFC 4648 base32.
 *
 * Implemented here rather than via a package (new dependencies need the
 * owner's approval) — the algorithm is a few lines of hash_hmac().
 */
final class Totp
{
    public const int PERIOD = 30;

    public const int DIGITS = 6;

    /** Accept the previous and next step too, for phone clock drift. */
    public const int WINDOW = 1;

    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new random secret: 20 bytes (160 bits, the RFC 4226 recommendation),
     * base32 encoded — 32 characters.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function timeStep(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    /**
     * The code for one time step.
     */
    public static function codeAt(string $secret, int $timeStep): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('N*', 0, $timeStep);
        $hash = hash_hmac('sha1', $counter, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step $code matches within ±WINDOW of now, or null. Callers
     * store the returned step and pass it back as $afterStep so the same
     * code can never be accepted twice.
     */
    public static function verify(string $secret, string $code, ?int $afterStep = null, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $current = self::timeStep($timestamp);

        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if ($afterStep !== null && $step <= $afterStep) {
                continue;
            }

            if (hash_equals(self::codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The otpauth:// URI authenticator apps read from the setup QR code.
     */
    public static function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($accountName)
            .'?'.http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper((string) preg_replace('/[\s=]+/', '', $encoded));
        $bits = '';

        foreach (str_split($encoded) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);

            if ($value === false) {
                continue;
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
