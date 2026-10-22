<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Mail\SecurityCodeMail;
use App\Models\User;
use App\Support\OneTimeSecret;
use App\Support\Totp;
use Illuminate\Support\Facades\Mail;

/**
 * Test helpers for account security: switch two-factor on directly (no
 * setup round-trip), produce the current authenticator code, and read the
 * code out of the last queued SecurityCodeMail (requires Mail::fake()).
 */
trait InteractsWithTwoFactor
{
    /**
     * @return array{secret: string, recovery_codes: list<string>}
     */
    protected function enableTwoFactor(User $user): array
    {
        $secret = Totp::generateSecret();
        $codes = ['AAAAA-BBBBB', 'CCCCC-DDDDD', 'EEEEE-FFFFF'];

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
            'two_factor_recovery_codes' => array_map(
                fn (string $code): string => OneTimeSecret::hash(OneTimeSecret::normalizeRecoveryCode($code)),
                $codes,
            ),
        ])->save();

        return ['secret' => $secret, 'recovery_codes' => $codes];
    }

    protected function totpCode(string $secret, int $stepOffset = 0): string
    {
        return Totp::codeAt($secret, Totp::timeStep() + $stepOffset);
    }

    protected function lastEmailedCode(?string $to = null): string
    {
        $code = null;

        Mail::assertQueued(SecurityCodeMail::class, function (SecurityCodeMail $mail) use (&$code, $to): bool {
            if ($to !== null && ! $mail->hasTo($to)) {
                return false;
            }

            $code = $mail->code;

            return true;
        });

        $this->assertNotNull($code, 'No security code email was queued.');

        return $code;
    }

    /** A 6-digit code that is NOT $code. */
    protected function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }
}
