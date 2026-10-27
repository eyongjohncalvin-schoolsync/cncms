<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Version numbers for cached screens, per tenant (cache audit, 2026-10-27).
 * Every cached list, page or total puts the versions of the data it shows in
 * its key — payments, customers, manuscripts, agents, spending — and the
 * models bump their version when they change (saved / deleted events, plus
 * the few bulk query-builder writes that skip events). A change from any
 * path (web, API, phone sync, queued job) then retires every cached screen
 * built on it, instead of each writer having to know which keys to forget.
 *
 * Inside a transaction the version is bumped once at the first write (so the
 * transaction's own redirect never reads the old cache) and once more after
 * commit (a read during the transaction may have cached the old rows); later
 * writes of the same transaction add nothing — a bill run of 10,000 rows
 * bumps twice, not 20,000 times. Same seed-then-increment as
 * NotificationService::bumpFeedVersion().
 */
final class CacheVersion
{
    public const string PAYMENTS = 'payments';

    public const string CUSTOMERS = 'customers';

    public const string MANUSCRIPTS = 'manuscripts';

    public const string AGENTS = 'agents';

    public const string SPENDING = 'spending';

    /** @var array<string, int> "tenant|name" => version, read once per request or job */
    private static array $memo = [];

    /** @var array<string, true> "tenant|name" bumped inside a transaction still open */
    private static array $pendingCommit = [];

    /** "v3.12" — the versions of $names, for a cache key. */
    public static function of(string ...$names): string
    {
        return 'v'.implode('.', array_map(fn (string $name): int => self::get($name), $names));
    }

    public static function get(string $name): int
    {
        return self::$memo[self::slot($name)] ??= (int) Cache::get(self::key($name), 0);
    }

    public static function bump(string ...$names): void
    {
        foreach ($names as $name) {
            $slot = self::slot($name);

            if (DB::transactionLevel() === 0) {
                self::increment($name);

                continue;
            }

            if (isset(self::$pendingCommit[$slot])) {
                continue;
            }

            self::$pendingCommit[$slot] = true;
            self::increment($name);

            DB::afterCommit(function () use ($name, $slot): void {
                unset(self::$pendingCommit[$slot]);
                self::increment($name);
            });
            DB::afterRollBack(function () use ($slot): void {
                unset(self::$pendingCommit[$slot]);
            });
        }
    }

    /** Called on tenancy changes, so a queue worker never carries versions between jobs. */
    public static function flush(): void
    {
        self::$memo = [];
        self::$pendingCommit = [];
    }

    private static function increment(string $name): void
    {
        Cache::add(self::key($name), 0);
        self::$memo[self::slot($name)] = (int) Cache::increment(self::key($name));
    }

    private static function key(string $name): string
    {
        return "cache_version:{$name}";
    }

    private static function slot(string $name): string
    {
        return (tenant()?->getTenantKey() ?? 'central').'|'.$name;
    }
}
