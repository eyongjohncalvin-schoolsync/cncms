<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\SecurityNoticeMail;
use App\Models\SecurityCode;
use App\Models\User;
use App\Support\BusinessTimezone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service account security shared by the web "My account" page and
 * the mobile API: password and email changes, the signed-in sessions and
 * devices list, "sign out everywhere else", and the security notice emails.
 *
 * Web sessions are rows of the central `sessions` table (database session
 * driver); mobile devices are the user's Sanctum tokens.
 */
class AccountSecurityService
{
    public function __construct(private readonly SecurityCodeService $codes) {}

    /**
     * Sets the new password, signs out every other session and device, and
     * tells the owner.
     */
    public function changePassword(User $user, string $newPassword, ?string $currentSessionId = null, ?int $currentTokenId = null): void
    {
        // Cast to 'hashed' on the User model.
        $user->update(['password' => $newPassword]);

        $this->signOutOtherSessions($user, $currentSessionId, $currentTokenId);

        $this->notify($user, SecurityNoticeMail::PASSWORD_CHANGED);
    }

    /**
     * Emails a confirmation code to the NEW address. Nothing changes until
     * that code comes back (confirmEmailChange()).
     */
    public function requestEmailChange(User $user, string $newEmail): void
    {
        $this->codes->issue($user, SecurityCode::PURPOSE_EMAIL_CHANGE, $newEmail);
    }

    public function pendingEmail(User $user): ?string
    {
        return $this->codes->pending($user, SecurityCode::PURPOSE_EMAIL_CHANGE)?->email;
    }

    public function cancelEmailChange(User $user): void
    {
        $this->codes->cancel($user, SecurityCode::PURPOSE_EMAIL_CHANGE);
    }

    /**
     * Switches the account to the pending address once its code matches,
     * and notifies the OLD address.
     *
     * @throws ValidationException when the code is wrong/expired/used or the
     *                             address was taken in the meantime
     */
    public function confirmEmailChange(User $user, string $code): void
    {
        $securityCode = $this->codes->verify($user, SecurityCode::PURPOSE_EMAIL_CHANGE, $code);

        if (! $securityCode || ! $securityCode->email) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }

        $newEmail = $securityCode->email;

        if (User::query()->where('email', $newEmail)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['code' => 'That email address is already used by another account.']);
        }

        $oldEmail = $user->email;

        $user->forceFill(['email' => $newEmail, 'email_verified_at' => now()])->save();

        $this->notify($user, SecurityNoticeMail::EMAIL_CHANGED, $oldEmail, $newEmail);
    }

    /**
     * The user's signed-in web sessions, most recent first. The session id
     * itself is never exposed — it IS the session cookie.
     *
     * @return list<array{ip_address: string|null, user_agent: string|null, last_active_at: string, is_current: bool}>
     */
    public function webSessions(User $user, ?string $currentSessionId = null): array
    {
        return $this->sessionsTable()
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active_at' => Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
                'is_current' => $currentSessionId !== null && hash_equals((string) $session->id, $currentSessionId),
            ])
            ->values()
            ->all();
    }

    /**
     * The user's signed-in mobile devices (Sanctum tokens), most recently
     * used first.
     *
     * @return list<array{id: int, name: string, last_used_at: string|null, created_at: string|null, is_current: bool}>
     */
    public function devices(User $user, ?int $currentTokenId = null): array
    {
        return $user->tokens()
            ->orderByRaw('last_used_at DESC NULLS LAST')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'is_current' => $currentTokenId !== null && $token->id === $currentTokenId,
            ])
            ->values()
            ->all();
    }

    /**
     * Ends every web session and revokes every device token except the
     * caller's own, and rotates the remember-me token so no persistent
     * login cookie survives either.
     */
    public function signOutOtherSessions(User $user, ?string $currentSessionId = null, ?int $currentTokenId = null): void
    {
        $sessions = $this->sessionsTable()->where('user_id', $user->id);

        if ($currentSessionId !== null) {
            $sessions->where('id', '!=', $currentSessionId);
        }

        $sessions->delete();

        $tokens = $user->tokens();

        if ($currentTokenId !== null) {
            $tokens->whereKeyNot($currentTokenId);
        }

        $tokens->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /**
     * Queues a security notice to the account owner — to $toEmail when
     * given (the OLD address after an email change).
     */
    public function notify(User $user, string $kind, ?string $toEmail = null, ?string $newEmail = null): void
    {
        $occurredAt = now()->setTimezone(BusinessTimezone::WAT)->format('l d M Y, H:i');

        Mail::to($toEmail ?? $user->email)->queue(new SecurityNoticeMail(
            $user->name,
            $kind,
            $occurredAt,
            request()->ip(),
            $newEmail,
        ));
    }

    /**
     * The central sessions table — on the session connection, never the
     * tenant one that tenancy may have made the default.
     */
    private function sessionsTable(): Builder
    {
        return DB::connection(config('session.connection') ?: 'pgsql')->table(config('session.table', 'sessions'));
    }
}
