<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\DevicePushToken;
use App\Models\Notification;
use Illuminate\Support\Collection;

interface PushTokenRepositoryInterface
{
    /**
     * Upsert by (user_id, device_id) — a fresh registration for the same
     * device (e.g. after a reinstall rotates the Expo token) replaces the
     * stored token and flips is_valid back to true rather than creating a
     * second row.
     */
    public function register(int $userId, string $deviceId, string $expoPushToken, string $platform): DevicePushToken;

    /**
     * Every currently-valid device token belonging to a user who: (a) holds
     * the `agent` tenant role, AND (b) is in $notification's audience
     * (broadcast_scope='all'/'user'/'role', including the investor special
     * case — though in practice no `agent`-role user is ever an investor
     * target, since 'role' scope only matches recipient_role='agent' or the
     * is_investor flag, never both at once for the same row). Mirrors
     * App\Repositories\Eloquent\NotificationRepository::audienceScope()'s
     * logic, joined against tenant_users instead of applied per-user, since
     * here we're resolving MANY recipients for ONE already-loaded
     * notification rather than many notifications for one known user.
     *
     * @return Collection<int, DevicePushToken>
     */
    public function tokensForAudience(Notification $notification): Collection;

    public function invalidateByToken(string $expoPushToken): void;

    public function invalidate(DevicePushToken $token): void;

    public function touchLastUsed(DevicePushToken $token): void;
}
