<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for POST /api/v1/devices/push-token
 * (App\Http\Requests\StorePushTokenRequest), handed to
 * App\Services\PushTokenService::register().
 */
final readonly class PushTokenData
{
    public function __construct(
        public string $deviceId,
        public string $expoPushToken,
        public string $platform,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            deviceId: $data['device_id'],
            expoPushToken: $data['expo_push_token'],
            platform: $data['platform'],
        );
    }
}
