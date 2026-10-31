<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\AccountBlockedMail;
use App\Mail\LoginAttackAlertMail;
use App\Models\LandlordActivity;
use App\Models\LoginFailure;
use App\Models\User;
use App\Support\BusinessTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Watches password sign-ins, web (AuthController) and mobile
 * (Api\AuthController), on top of the per-minute `login` rate limiter:
 *
 * - every refused password is recorded (LoginFailure);
 * - too many for one account within the window block that account's
 *   password sign-in for a while, from every device, and failing again
 *   after that block blocks it for longer
 *   (config('rate-limits.login_block')). Never permanent, so nobody can
 *   lock an owner out for good; email-code sign-in keeps working;
 * - the account's owner is emailed when a block starts, and every
 *   landlord when one IP fails on many different accounts;
 * - a correct password, or a landlord's Unblock (Landlord → Security),
 *   clears the count.
 *
 * An unknown username/email is counted under what was typed, so blocking
 * behaves the same whether or not the account exists.
 */
class LoginGuard
{
    /**
     * When the account's password sign-in is blocked until, or null.
     */
    public function blockedUntil(?User $user, string $identifier): ?Carbon
    {
        $config = config('rate-limits.login_block');

        // A block runs from the latest failure, so look back far enough to
        // see one still running, and count the window before that failure.
        $failures = $this->counted($user, $identifier, $config['window_minutes'] + $config['long_block_minutes'])->pluck('created_at');
        $latest = $failures->max();

        if ($latest === null) {
            return null;
        }

        $latest = Carbon::parse($latest);
        $count = $failures->filter(fn ($at) => Carbon::parse($at)->gte($latest->copy()->subMinutes($config['window_minutes'])))->count();

        // Failing again once a first block is over means someone is still
        // trying: the longer block.
        $minutes = match (true) {
            $count > $config['block_after'] => $config['long_block_minutes'],
            $count === $config['block_after'] => $config['block_minutes'],
            default => null,
        };

        if ($minutes === null) {
            return null;
        }

        $until = $latest->copy()->addMinutes($minutes);

        return $until->isFuture() ? $until : null;
    }

    /**
     * Records a wrong password (or unknown account). Returns when the
     * account is now blocked until, if this attempt started a block.
     */
    public function recordFailure(?User $user, string $identifier, string $channel, Request $request): ?Carbon
    {
        $this->record($user, $identifier, LoginFailure::WRONG_PASSWORD, $channel, $request);

        $config = config('rate-limits.login_block');
        $count = $this->counted($user, $identifier)->count();
        $blockedUntil = null;

        if ($count === $config['block_after'] || $count === $config['block_after'] + 1) {
            $blockedUntil = $this->blockedUntil($user, $identifier);

            if ($blockedUntil !== null) {
                $this->announceBlock($user, $identifier, $count, $blockedUntil, $request);
            }
        }

        $this->watchIp($request);

        return $blockedUntil;
    }

    /** An attempt refused because the account is blocked (not counted). */
    public function recordBlockedAttempt(?User $user, string $identifier, string $channel, Request $request): void
    {
        $this->record($user, $identifier, LoginFailure::WHILE_BLOCKED, $channel, $request);
    }

    /**
     * Stops the account's past failures counting: a correct password, or
     * a landlord's Unblock. Returns how many were cleared.
     */
    public function clear(?User $user, string $identifier): int
    {
        return $this->scope($user, $identifier)->whereNull('cleared_at')->update(['cleared_at' => now()]);
    }

    /**
     * Every account blocked right now, longest block first.
     *
     * @return list<array{user: ?User, identifier: string, failures: int, until: Carbon}>
     */
    public function blockedAccounts(): array
    {
        $config = config('rate-limits.login_block');

        return LoginFailure::query()
            ->with('user:id,uuid,name,username,email')
            ->where('reason', LoginFailure::WRONG_PASSWORD)
            ->whereNull('cleared_at')
            ->where('created_at', '>=', now()->subMinutes($config['window_minutes'] + $config['long_block_minutes']))
            ->get()
            ->groupBy(fn (LoginFailure $failure) => $failure->user_id !== null ? 'user:'.$failure->user_id : 'typed:'.$failure->identifier)
            ->map(function ($failures) {
                $first = $failures->first();
                $until = $this->blockedUntil($first->user, $first->identifier);

                return $until ? ['user' => $first->user, 'identifier' => $first->identifier, 'failures' => $failures->count(), 'until' => $until] : null;
            })
            ->filter()
            ->sortByDesc(fn (array $blocked) => $blocked['until'])
            ->values()
            ->all();
    }

    public function blockedMessage(Carbon $until): string
    {
        return 'Too many wrong passwords. For your safety, password sign-in for this account is blocked until '
            .$until->copy()->setTimezone(BusinessTimezone::WAT)->format('H:i')
            .' (Cameroon time). You can still sign in with a code sent to your email.';
    }

    public static function normalize(string $identifier): string
    {
        return mb_substr(mb_strtolower(trim($identifier)), 0, 191);
    }

    /**
     * @return Builder<LoginFailure>
     */
    private function scope(?User $user, string $identifier): Builder
    {
        return LoginFailure::query()->when(
            $user !== null,
            fn (Builder $query) => $query->where('user_id', $user?->id),
            fn (Builder $query) => $query->whereNull('user_id')->where('identifier', self::normalize($identifier)),
        );
    }

    /**
     * The uncleared wrong passwords of the last $minutes (default: the window).
     *
     * @return Builder<LoginFailure>
     */
    private function counted(?User $user, string $identifier, ?int $minutes = null): Builder
    {
        return $this->scope($user, $identifier)
            ->where('reason', LoginFailure::WRONG_PASSWORD)
            ->whereNull('cleared_at')
            ->where('created_at', '>=', now()->subMinutes($minutes ?? config('rate-limits.login_block.window_minutes')));
    }

    private function record(?User $user, string $identifier, string $reason, string $channel, Request $request): void
    {
        LoginFailure::create([
            'user_id' => $user?->id,
            'identifier' => self::normalize($identifier),
            'reason' => $reason,
            'channel' => $channel,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }

    private function announceBlock(?User $user, string $identifier, int $count, Carbon $until, Request $request): void
    {
        $who = $user ? "{$user->name} ({$user->username})" : '"'.self::normalize($identifier).'" (no such account)';

        LandlordActivity::record(
            'security.account_blocked',
            "Blocked password sign-in for {$who} after {$count} wrong passwords",
            $user ? 'user' : null,
            $user?->uuid,
            ['identifier' => self::normalize($identifier), 'failures' => $count, 'until' => $until->toIso8601String(), 'ip_address' => $request->ip()],
        );

        if ($user !== null && filled($user->email)) {
            Mail::to($user->email)->queue(new AccountBlockedMail(
                $user->name,
                $count,
                $until->copy()->setTimezone(BusinessTimezone::WAT)->format('l d M Y, H:i'),
                (string) $request->ip(),
            ));
        }
    }

    /**
     * One IP failing on many different accounts looks like someone trying
     * passwords across the platform: tell the landlords, once an hour per IP.
     */
    private function watchIp(Request $request): void
    {
        $ip = $request->ip();
        $config = config('rate-limits.login_block');

        if ($ip === null) {
            return;
        }

        $accounts = LoginFailure::query()
            ->where('ip_address', $ip)
            ->where('reason', LoginFailure::WRONG_PASSWORD)
            ->where('created_at', '>=', now()->subMinutes($config['window_minutes']))
            ->distinct()
            ->count('identifier');

        if ($accounts < $config['ip_alert_accounts'] || ! Cache::add("security:ip-alert:{$ip}", true, now()->addHour())) {
            return;
        }

        LandlordActivity::record('security.ip_alert', "One device ({$ip}) tried wrong passwords on {$accounts} different accounts within an hour", null, null, ['ip_address' => $ip, 'accounts' => $accounts]);

        User::query()->where('is_landlord', true)->where('status', 'active')->whereNotNull('email')->where('email', '!=', '')
            ->each(fn (User $landlord) => Mail::to($landlord->email)->queue(new LoginAttackAlertMail($landlord->name, $ip, $accounts)));
    }
}
