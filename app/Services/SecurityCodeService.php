<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\SecurityCodeMail;
use App\Models\SecurityCode;
use App\Models\User;
use App\Support\OneTimeSecret;
use Illuminate\Support\Facades\Mail;

/**
 * One-time 6-digit codes sent by email: passwordless sign-in, the
 * "email me a code instead" two-factor fallback, and confirming a new email
 * address. Each code is single use, expires after TTL_MINUTES and dies
 * after MAX_ATTEMPTS wrong guesses. Issuing a new code for the same
 * purpose cancels the user's previous one. Sending is additionally
 * throttled per route (config/rate-limits.php `security-codes`).
 */
class SecurityCodeService
{
    public const int TTL_MINUTES = 10;

    public const int MAX_ATTEMPTS = 5;

    /**
     * Creates a code and emails it — to $email when given (a new address
     * being confirmed), otherwise to the account's own address.
     */
    public function issue(User $user, string $purpose, ?string $email = null): SecurityCode
    {
        $this->cancel($user, $purpose);

        $code = OneTimeSecret::numericCode();

        $securityCode = SecurityCode::query()->create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => OneTimeSecret::hash($code),
            'email' => $email,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        Mail::to($email ?? $user->email)->queue(new SecurityCodeMail($user->name, $code, $purpose, self::TTL_MINUTES));

        return $securityCode;
    }

    /**
     * The user's live (unused, unexpired, not locked out) code for $purpose.
     */
    public function pending(User $user, string $purpose): ?SecurityCode
    {
        return SecurityCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->latest('id')
            ->first();
    }

    /**
     * Checks $code against the user's live code and, when it matches,
     * consumes it (single use — the consume is an atomic conditional
     * update, so two simultaneous submissions cannot both succeed). A
     * wrong guess counts towards MAX_ATTEMPTS. Returns the consumed code
     * (its `email` is the address being confirmed, for an email change).
     */
    public function verify(User $user, string $purpose, string $code): ?SecurityCode
    {
        $securityCode = $this->pending($user, $purpose);

        if (! $securityCode) {
            return null;
        }

        $code = (string) preg_replace('/\s+/', '', $code);

        if (! OneTimeSecret::matches($securityCode->code_hash, $code)) {
            $securityCode->increment('attempts');

            return null;
        }

        $consumed = SecurityCode::query()
            ->whereKey($securityCode->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return $consumed === 1 ? $securityCode->refresh() : null;
    }

    public function cancel(User $user, string $purpose): void
    {
        SecurityCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
