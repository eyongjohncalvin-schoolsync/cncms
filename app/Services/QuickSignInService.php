<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PersonalAccessToken;
use App\Models\User;

/**
 * Fingerprint sign-in on the phone (owner, 2026-11-01). After a full
 * sign-in, the phone can ask for a quick sign-in key and keep it locked
 * behind the fingerprint; later it trades the key for a normal token
 * without the password.
 *
 * The key is a Sanctum token whose only ability is QUICK_SIGN_IN. It can
 * never call the API itself (AppServiceProvider refuses such tokens), it
 * survives "Log out" (that ends the session, not the phone's fingerprint
 * sign-in), and it goes wherever other tokens go: "Signed-in devices",
 * a password change, signing out other devices, deactivation.
 */
final class QuickSignInService
{
    public const string QUICK_SIGN_IN = 'quick-sign-in';

    /**
     * A new key for this phone — replaces the one it had, if any.
     *
     * @return array{id: int, key: string}
     */
    public function issue(User $user, string $deviceName): array
    {
        $name = $this->tokenName($deviceName);

        $user->tokens()->where('name', $name)->get()
            ->filter(fn (PersonalAccessToken $token): bool => self::isQuickSignInKey($token))
            ->each->delete();

        $token = $user->createToken($name, [self::QUICK_SIGN_IN]);

        return ['id' => $token->accessToken->id, 'key' => $token->plainTextToken];
    }

    /**
     * The user a key belongs to, or null when it is unknown, revoked or
     * not a quick sign-in key.
     */
    public function redeem(string $key): ?User
    {
        $token = PersonalAccessToken::findToken($key);

        if (! $token instanceof PersonalAccessToken || ! self::isQuickSignInKey($token) || ! $token->tokenable instanceof User) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $token->tokenable;
    }

    /** Turns fingerprint sign-in off for one of the user's own keys. */
    public function revoke(User $user, int $tokenId): void
    {
        $user->tokens()->whereKey($tokenId)->get()
            ->filter(fn (PersonalAccessToken $token): bool => self::isQuickSignInKey($token))
            ->each->delete();
    }

    public static function isQuickSignInKey(PersonalAccessToken $token): bool
    {
        return in_array(self::QUICK_SIGN_IN, (array) $token->abilities, true);
    }

    private function tokenName(string $deviceName): string
    {
        $deviceName = trim($deviceName);

        return mb_substr('Fingerprint sign-in · '.($deviceName === '' ? 'phone' : $deviceName), 0, 120);
    }
}
