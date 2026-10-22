<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OneTimeSecret;
use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/**
 * App\Support\Totp against the RFC 6238 appendix B SHA1 test vectors
 * (secret "12345678901234567890", the last 6 of each 8-digit value).
 */
class TotpTest extends TestCase
{
    private function rfcSecret(): string
    {
        return Totp::base32Encode('12345678901234567890');
    }

    public function test_base32_round_trips_and_matches_rfc_4648(): void
    {
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        $this->assertSame('foobar', Totp::base32Decode('MZXW6YTBOI'));
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb oi======'));
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $this->rfcSecret());
    }

    public function test_codes_match_the_rfc_6238_sha1_vectors(): void
    {
        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame($expected, Totp::codeAt($this->rfcSecret(), Totp::timeStep($timestamp)), "T={$timestamp}");
        }
    }

    public function test_verify_accepts_one_step_of_drift_either_way_and_no_more(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;
        $step = Totp::timeStep($now);

        $this->assertSame($step, Totp::verify($secret, Totp::codeAt($secret, $step), null, $now));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::codeAt($secret, $step - 1), null, $now));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::codeAt($secret, $step + 1), null, $now));
        $this->assertNull(Totp::verify($secret, Totp::codeAt($secret, $step - 2), null, $now));
        $this->assertNull(Totp::verify($secret, Totp::codeAt($secret, $step + 2), null, $now));
    }

    public function test_verify_refuses_a_step_already_used(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;
        $step = Totp::timeStep($now);

        $this->assertNull(Totp::verify($secret, Totp::codeAt($secret, $step), $step, $now));
    }

    public function test_verify_rejects_malformed_codes(): void
    {
        $secret = Totp::generateSecret();

        $this->assertNull(Totp::verify($secret, ''));
        $this->assertNull(Totp::verify($secret, '12345'));
        $this->assertNull(Totp::verify($secret, 'abcdef'));
    }

    public function test_generated_secret_is_160_bits_of_base32(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
    }

    public function test_provisioning_uri_carries_issuer_account_and_secret(): void
    {
        $uri = Totp::provisioningUri('ABCDEF', 'kelvin@example.test', 'CNCMS');

        $this->assertStringStartsWith('otpauth://totp/CNCMS:kelvin%40example.test?', $uri);
        $this->assertStringContainsString('secret=ABCDEF', $uri);
        $this->assertStringContainsString('issuer=CNCMS', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function test_recovery_codes_are_normalised_for_matching(): void
    {
        $code = OneTimeSecret::recoveryCode();

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code);
        $this->assertSame(str_replace('-', '', $code), OneTimeSecret::normalizeRecoveryCode(strtolower($code)));
        $this->assertMatchesRegularExpression('/^\d{6}$/', OneTimeSecret::numericCode());
    }
}
