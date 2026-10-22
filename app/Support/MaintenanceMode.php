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
    /**
     * The window users should be told about: the running one, else the next
     * scheduled one.
     */
    public static function current(): ?MaintenanceWindow
    {
        return MaintenanceWindow::query()->live()->orderBy('starts_at')->first();
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
