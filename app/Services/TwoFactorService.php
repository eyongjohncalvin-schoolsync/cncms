<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\SecurityNoticeMail;
use App\Models\User;
use App\Support\OneTimeSecret;
use App\Support\QrCode;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Authenticator-app (TOTP) two-factor authentication for a user:
 *
 *   1. beginSetup() stores a fresh secret (encrypted cast) — 2FA is still OFF.
 *   2. confirm() with a code from the app turns it ON and returns 8
 *      single-use recovery codes, shown once (only their hashes are kept).
 *   3. disable() turns it off again (callers check password + code first).
 *
 * verifyCode()/useRecoveryCode() are what the sign-in challenge calls.
 */
class TwoFactorService
{
    public const int RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly AccountSecurityService $account) {}

    public function beginSetup(User $user): string
    {
        if ($user->hasTwoFactorEnabled()) {
            throw new RuntimeException('Two-factor authentication is already on.');
        }

        $secret = Totp::generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        return $secret;
    }

    /**
     * What the setup screen shows while a secret awaits confirmation, or
     * null when there is no pending setup.
     *
     * @return array{secret: string, otpauth_url: string, qr_code: string}|null
     */
    public function pendingSetup(User $user): ?array
    {
        if ($user->hasTwoFactorEnabled() || $user->two_factor_secret === null) {
            return null;
        }

        $otpauthUrl = Totp::provisioningUri($user->two_factor_secret, $user->email, (string) config('app.name', 'CNCMS'));

        return [
            'secret' => $user->two_factor_secret,
            'otpauth_url' => $otpauthUrl,
            'qr_code' => QrCode::pngDataUri($otpauthUrl, 5),
        ];
    }

    /**
     * Turns 2FA on when $code matches the pending secret. Returns the plain
     * recovery codes (to show once), or null for a wrong code.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->hasTwoFactorEnabled() || $user->two_factor_secret === null) {
            return null;
        }

        $step = Totp::verify($user->two_factor_secret, $code);

        if ($step === null) {
            return null;
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => $step,
        ])->save();

        $codes = $this->storeNewRecoveryCodes($user);

        $this->account->notify($user, SecurityNoticeMail::TWO_FACTOR_ENABLED);

        return $codes;
    }

    public function disable(User $user): void
    {
        $wasEnabled = $user->hasTwoFactorEnabled();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        if ($wasEnabled) {
            $this->account->notify($user, SecurityNoticeMail::TWO_FACTOR_DISABLED);
        }
    }

    /**
     * Replaces every recovery code; the old ones stop working.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw new RuntimeException('Two-factor authentication is off.');
        }

        $codes = $this->storeNewRecoveryCodes($user);

        $this->account->notify($user, SecurityNoticeMail::RECOVERY_CODES_REGENERATED);

        return $codes;
    }

    public function recoveryCodesRemaining(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /**
     * True when $code is the current authenticator code. A code is
     * accepted at most once: its time step is recorded with an atomic
     * conditional update, so a replay (or a race) of the same code fails.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $step = Totp::verify($user->two_factor_secret, $code, $user->two_factor_last_used_step);

        if ($step === null) {
            return false;
        }

        $recorded = User::query()
            ->whereKey($user->id)
            ->where(fn ($query) => $query
                ->whereNull('two_factor_last_used_step')
                ->orWhere('two_factor_last_used_step', '<', $step))
            ->update(['two_factor_last_used_step' => $step]);

        if ($recorded !== 1) {
            return false;
        }

        $user->two_factor_last_used_step = $step;
        $user->syncOriginalAttribute('two_factor_last_used_step');

        return true;
    }

    /**
     * True when $code is one of the unused recovery codes; it is removed so
     * it can never be used again.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $hash = OneTimeSecret::hash(OneTimeSecret::normalizeRecoveryCode($code));

        // Row lock: two simultaneous sign-ins can't both spend one code.
        $used = DB::connection('pgsql')->transaction(function () use ($user, $hash): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $remaining = $locked->two_factor_recovery_codes ?? [];
            $index = null;

            foreach ($remaining as $position => $storedHash) {
                if (hash_equals((string) $storedHash, $hash)) {
                    $index = $position;
                }
            }

            if ($index === null) {
                return false;
            }

            unset($remaining[$index]);

            $locked->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();

            return true;
        });

        if ($used) {
            $user->refresh();
        }

        return $used;
    }

    /**
     * @return list<string>
     */
    private function storeNewRecoveryCodes(User $user): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = OneTimeSecret::recoveryCode();
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(
                fn (string $code): string => OneTimeSecret::hash(OneTimeSecret::normalizeRecoveryCode($code)),
                $codes,
            ),
        ])->save();

        return $codes;
    }
}
