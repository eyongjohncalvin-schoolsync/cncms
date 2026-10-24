<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FieldDevice;
use App\Models\User;
use App\Support\BranchAttribution;
use App\Support\ReceiptSecurityCode;

/**
 * Registers and revokes the phones that issue receipts in the field
 * (docs/plans/cash-controls.md, wave 2). Registration is the only moment the
 * device secret ever leaves the server — the app stores it in the phone's
 * secure store and signs every receipt with it, online or not.
 */
class FieldDeviceService
{
    /**
     * @return array{device: FieldDevice, secret: string}
     */
    public function register(User $user, string $name): array
    {
        $secret = ReceiptSecurityCode::newDeviceSecret();

        $device = FieldDevice::query()->create([
            'user_id' => $user->id,
            'branch_id' => BranchAttribution::forUser($user),
            'name' => $name,
            'secret' => $secret,
            'registered_at' => now(),
            'last_seen_at' => now(),
        ]);

        return ['device' => $device, 'secret' => $secret];
    }

    /**
     * A lost or replaced phone: from now on its codes are refused, so a
     * receipt "issued" on it can no longer be recorded. Its open digital
     * blocks are closed; their unused numbers stay unused and therefore
     * show up as unaccounted until someone cancels them.
     */
    public function revoke(FieldDevice $device, User $actor): void
    {
        if ($device->isRevoked()) {
            return;
        }

        $device->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);

        $device->blocks()->where('status', 'open')->get()->each->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $actor->id,
        ]);
    }

    /**
     * The registered, still-active device $user is calling from, or null.
     */
    public function activeFor(User $user, ?string $deviceUuid): ?FieldDevice
    {
        if ($deviceUuid === null || $deviceUuid === '') {
            return null;
        }

        $device = FieldDevice::query()
            ->where('uuid', $deviceUuid)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->first();

        $device?->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $device;
    }
}
