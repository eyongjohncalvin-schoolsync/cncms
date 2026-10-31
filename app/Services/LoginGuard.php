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
use Illuminate\Support\Collection;
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
 * - too many from one IP address, whatever usernames were typed, block
 *   password sign-in from that address (the device block; trusted
 *   addresses excepted);
 * - the account's owner is emailed when a block starts, and every
 *   landlord when a device is blocked or one IP fails on many accounts;
 * - a correct password, or a landlord's Unblock (Landlord → Security),
 *   clears an account's count; only "Unblock device" clears an address's.
 *
 * An unknown username/email is counted under what was typed, so blocking
 * behaves the same whether or not the account exists.
 */
class LoginGuard
{
    /**
     * Why password sign-in is refused right now for this account from this
     * device, or null. The device block wins: it covers every account.
     *
     * @return array{code: 'DEVICE_BLOCKED'|'ACCOUNT_BLOCKED', until: Carbon, message: string}|null
     */
    public function refusal(?User $user, string $identifier, Request $request): ?array
    {
        if ($until = $this->deviceBlockedUntil($request->ip())) {
            return ['code' => 'DEVICE_BLOCKED', 'until' => $until, 'message' => $this->deviceBlockedMessage($until)];
        }

        if ($until = $this->blockedUntil($user, $identifier)) {
            return ['code' => 'ACCOUNT_BLOCKED', 'until' => $until, 'message' => $this->blockedMessage($until)];
        }

        return null;
    }

    /**
     * When password sign-in from this IP address is blocked until, or null:
     * too many wrong passwords from it within the window, whatever
     * usernames were typed. Trusted addresses are never blocked.
     */
    public function deviceBlockedUntil(?string $ip): ?Carbon
    {
        $config = config('rate-limits.login_block');

        if ($ip === null || in_array($ip, $config['trusted_ips'], true)) {
            return null;
        }

        $failures = $this->deviceCounted($ip, $config['window_minutes'] + $config['device_block_minutes'])->pluck('created_at');

        return $this->blockRunningUntil($failures, $config['device_block_after'], $config['device_block_minutes']);
    }

    /**
     * When the account's password sign-in is blocked until, or null.
     */
    public function blockedUntil(?User $user, string $identifier): ?Carbon
    {
        $config = config('rate-limits.login_block');

        $failures = $this->counted($user, $identifier, $config['window_minutes'] + $config['long_block_minutes'])->pluck('created_at');

        // Failing again once a first block is over means someone is still
        // trying: the longer block.
        return $this->blockRunningUntil($failures, $config['block_after'], $config['block_minutes'], $config['long_block_minutes']);
    }

    /**
     * A block runs from the latest failure: count the window before it, and
     * the block (if the count reached $after) ends $minutes after it —
     * $longMinutes once the count went past $after.
     *
     * @param  Collection<int, mixed>  $failureTimes  far enough back to see a running block
     */
    private function blockRunningUntil(Collection $failureTimes, int $after, int $minutes, ?int $longMinutes = null): ?Carbon
    {
        $latest = $failureTimes->max();

        if ($latest === null) {
            return null;
        }

        $latest = Carbon::parse($latest);
        $from = $latest->copy()->subMinutes(config('rate-limits.login_block.window_minutes'));
        $count = $failureTimes->filter(fn ($at) => Carbon::parse($at)->gte($from))->count();

        $length = match (true) {
            $longMinutes !== null && $count > $after => $longMinutes,
            $count >= $after => $minutes,
            default => null,
        };

        $until = $length === null ? null : $latest->copy()->addMinutes($length);

        return $until?->isFuture() ? $until : null;
    }

    /**
     * Records a wrong password (or unknown account), and announces any
     * block it starts. Ask refusal() afterwards for what to tell the person.
     */
    public function recordFailure(?User $user, string $identifier, string $channel, Request $request): void
    {
        $this->record($user, $identifier, LoginFailure::WRONG_PASSWORD, $channel, $request);

        $config = config('rate-limits.login_block');
        $count = $this->counted($user, $identifier)->count();

        if ($count === $config['block_after'] || $count === $config['block_after'] + 1) {
            if ($blockedUntil = $this->blockedUntil($user, $identifier)) {
                $this->announceBlock($user, $identifier, $count, $blockedUntil, $request);
            }
        }

        $ip = $request->ip();

        if ($ip !== null && $this->deviceCounted($ip)->count() === $config['device_block_after']) {
            if ($deviceBlockedUntil = $this->deviceBlockedUntil($ip)) {
                $this->announceDeviceBlock($ip, $deviceBlockedUntil);
            }
        }

        $this->watchIp($request);
    }

    /** An attempt refused because the account or device is blocked (not counted). */
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
     * Stops the address's past failures counting against it (a landlord's
     * "Unblock device"). Accounts keep their own counts.
     */
    public function clearDevice(string $ip): int
    {
        return LoginFailure::query()->where('ip_address', $ip)->whereNull('ip_cleared_at')->update(['ip_cleared_at' => now()]);
    }

    /**
     * Every IP address blocked right now, longest block first.
     *
     * @return list<array{ip_address: string, failures: int, accounts: int, until: Carbon}>
     */
    public function blockedDevices(): array
    {
        $config = config('rate-limits.login_block');

        return LoginFailure::query()
            ->where('reason', LoginFailure::WRONG_PASSWORD)
            ->whereNull('ip_cleared_at')
            ->whereNotNull('ip_address')
            ->where('created_at', '>=', now()->subMinutes($config['window_minutes'] + $config['device_block_minutes']))
            ->get(['ip_address', 'identifier'])
            ->groupBy('ip_address')
            ->map(function ($failures, string $ip) {
                $until = $this->deviceBlockedUntil($ip);

                return $until ? ['ip_address' => $ip, 'failures' => $failures->count(), 'accounts' => $failures->pluck('identifier')->unique()->count(), 'until' => $until] : null;
            })
            ->filter()
            ->sortByDesc(fn (array $blocked) => $blocked['until'])
            ->values()
            ->all();
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

    public function deviceBlockedMessage(Carbon $until): string
    {
        return 'Too many wrong passwords from this network. For safety, password sign-in from here is blocked until '
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

    /**
     * The wrong passwords from one address that still count against it, of
     * the last $minutes (default: the window).
     *
     * @return Builder<LoginFailure>
     */
    private function deviceCounted(string $ip, ?int $minutes = null): Builder
    {
        return LoginFailure::query()
            ->where('ip_address', $ip)
            ->where('reason', LoginFailure::WRONG_PASSWORD)
            ->whereNull('ip_cleared_at')
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

    private function announceDeviceBlock(string $ip, Carbon $until): void
    {
        $failures = $this->deviceCounted($ip)->count();
        $accounts = $this->deviceCounted($ip)->distinct()->count('identifier');
        $untilText = $until->copy()->setTimezone(BusinessTimezone::WAT)->format('l d M Y, H:i');

        LandlordActivity::record('security.device_blocked', "Blocked password sign-in from {$ip} after {$failures} wrong passwords on {$accounts} account(s)", null, null, ['ip_address' => $ip, 'failures' => $failures, 'accounts' => $accounts, 'until' => $until->toIso8601String()]);

        User::query()->where('is_landlord', true)->where('status', 'active')->whereNotNull('email')->where('email', '!=', '')
            ->each(fn (User $landlord) => Mail::to($landlord->email)->queue(new LoginAttackAlertMail($landlord->name, $ip, $accounts, $untilText)));
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
