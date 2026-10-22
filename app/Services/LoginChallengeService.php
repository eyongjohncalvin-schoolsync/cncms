<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginChallenge;
use App\Models\SecurityCode;
use App\Models\User;
use App\Support\OneTimeSecret;

/**
 * The second step of signing in for a user with two-factor on. After the
 * first factor passes, start() records a challenge and returns a random
 * token (kept in the web session, or handed to the mobile app) — the user
 * is NOT signed in yet. passes() then checks the second factor: an
 * authenticator code, a recovery code, or (only when the first factor was
 * the password) a code emailed to the account. Challenges expire after
 * TTL_MINUTES, die after MAX_ATTEMPTS wrong codes and are single use.
 */
class LoginChallengeService
{
    public const int TTL_MINUTES = 10;

    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly SecurityCodeService $codes,
    ) {}

    /**
     * @param  LoginChallenge::FACTOR_*  $firstFactor
     * @return string the plain challenge token
     */
    public function start(User $user, string $firstFactor): string
    {
        $token = OneTimeSecret::token();

        LoginChallenge::query()->create([
            'user_id' => $user->id,
            'token_hash' => OneTimeSecret::hash($token),
            'first_factor' => $firstFactor,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $token;
    }

    /**
     * The live challenge for $token: unused, unexpired, not locked out, for
     * a still-active user. Null otherwise.
     */
    public function find(?string $token): ?LoginChallenge
    {
        if ($token === null || $token === '') {
            return null;
        }

        $challenge = LoginChallenge::query()
            ->with('user')
            ->where('token_hash', OneTimeSecret::hash($token))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->first();

        if (! $challenge || ! $challenge->user?->isActive()) {
            return null;
        }

        return $challenge;
    }

    /**
     * Emails a fallback code for the second factor — only allowed when the
     * first factor was the password (an email code can't be both factors).
     */
    public function sendEmailCode(LoginChallenge $challenge): bool
    {
        if (! in_array(LoginChallenge::METHOD_EMAIL, $challenge->methods(), true)) {
            return false;
        }

        $this->codes->issue($challenge->user, SecurityCode::PURPOSE_TWO_FACTOR);

        return true;
    }

    /**
     * True when $code satisfies the second factor by $method. On success
     * the challenge is consumed (atomically, so it can only ever sign in
     * once); a wrong code counts towards MAX_ATTEMPTS.
     */
    public function passes(LoginChallenge $challenge, string $method, string $code): bool
    {
        $user = $challenge->user;

        $valid = in_array($method, $challenge->methods(), true) && match ($method) {
            LoginChallenge::METHOD_TOTP => $this->twoFactor->verifyCode($user, $code),
            LoginChallenge::METHOD_RECOVERY => $this->twoFactor->useRecoveryCode($user, $code),
            LoginChallenge::METHOD_EMAIL => $this->codes->verify($user, SecurityCode::PURPOSE_TWO_FACTOR, $code) !== null,
            default => false,
        };

        if (! $valid) {
            $challenge->increment('attempts');

            return false;
        }

        return LoginChallenge::query()
            ->whereKey($challenge->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]) === 1;
    }

    /**
     * True when the challenge just used its last allowed attempt.
     */
    public function exhausted(LoginChallenge $challenge): bool
    {
        return $challenge->attempts >= self::MAX_ATTEMPTS;
    }
}
