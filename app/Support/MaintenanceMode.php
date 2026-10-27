<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MaintenanceWindow;
use App\Models\User;

/**
 * Platform maintenance gate. A landlord schedules a MaintenanceWindow; until
 * it starts every page shows a countdown banner, and while it is active
 * every tenant user is held on the maintenance page (ResolveTenantWeb) or
 * gets a 503 MAINTENANCE (ResolveTenant). Landlords pass through so they
 * can do the work the window is for.
 *
 * Deliberately not Laravel's `php artisan down`: that locks out landlords
 * too and has no countdown or advance notice.
 */
class MaintenanceMode
{
    /** @var array{0: float, 1: ?MaintenanceWindow}|null when current() last read, and what */
    private static ?array $memo = null;

    /**
     * The window users should be told about: the running one, else the next
     * scheduled one.
     */
    public static function current(): ?MaintenanceWindow
    {
        // A page load asks twice (the tenant middleware, then the shared
        // banner prop): one query serves both. Kept for a few seconds only,
        // so a long-lived worker or scheduler never sees an old window.
        if (self::$memo !== null && microtime(true) - self::$memo[0] < 5) {
            return self::$memo[1];
        }

        $window = MaintenanceWindow::query()->live()->orderBy('starts_at')->first();
        self::$memo = [microtime(true), $window];

        return $window;
    }

    /** Forgets the memo — when a window is saved, and between tests. */
    public static function flushMemo(): void
    {
        self::$memo = null;
    }

    public static function active(): ?MaintenanceWindow
    {
        $window = self::current();

        return $window?->isActive() ? $window : null;
    }

    public static function blocks(User $user): ?MaintenanceWindow
    {
        return $user->is_landlord ? null : self::active();
    }
}
