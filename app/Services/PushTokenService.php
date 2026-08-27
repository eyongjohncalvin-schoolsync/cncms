<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\PushTokenData;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Repositories\Contracts\PushTokenRepositoryInterface;

/**
 * Public API for registering a mobile device's Expo push token — the
 * client surface App\Http\Controllers\Api\PushTokenController calls.
 * Registration is intentionally the ONLY write path here: there is no
 * corresponding "unregister" endpoint for v1 (a stale/invalid token is
 * discovered and flipped inactive naturally by App\Jobs\
 * SendPushNotificationJob/CheckPushReceiptsJob's ticket handling instead —
 * see PushTokenRepositoryInterface::invalidate()).
 */
class PushTokenService
{
    public function __construct(
        private readonly PushTokenRepositoryInterface $pushTokens,
    ) {}

    public function register(User $user, PushTokenData $data): DevicePushToken
    {
        return $this->pushTokens->register($user->id, $data->deviceId, $data->expoPushToken, $data->platform);
    }
}
