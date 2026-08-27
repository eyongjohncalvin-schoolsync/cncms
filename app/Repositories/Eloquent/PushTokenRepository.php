<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\DevicePushToken;
use App\Models\Notification;
use App\Models\TenantUser;
use App\Repositories\Contracts\PushTokenRepositoryInterface;
use Illuminate\Support\Collection;

class PushTokenRepository implements PushTokenRepositoryInterface
{
    public function register(int $userId, string $deviceId, string $expoPushToken, string $platform): DevicePushToken
    {
        return DevicePushToken::query()->updateOrCreate(
            ['user_id' => $userId, 'device_id' => $deviceId],
            [
                'expo_push_token' => $expoPushToken,
                'platform' => $platform,
                'is_valid' => true,
                'registered_at' => now(),
            ],
        );
    }

    public function tokensForAudience(Notification $notification): Collection
    {
        $agentUserIds = $this->agentUserIdsInAudience($notification);

        if ($agentUserIds->isEmpty()) {
            return new Collection;
        }

        return DevicePushToken::query()
            ->where('is_valid', true)
            ->whereIn('user_id', $agentUserIds)
            ->get();
    }

    public function invalidateByToken(string $expoPushToken): void
    {
        DevicePushToken::query()->where('expo_push_token', $expoPushToken)->update(['is_valid' => false]);
    }

    public function invalidate(DevicePushToken $token): void
    {
        $token->update(['is_valid' => false]);
    }

    public function touchLastUsed(DevicePushToken $token): void
    {
        $token->update(['last_used_at' => now()]);
    }

    /**
     * Push-eligible recipients are always `role = 'agent'` AND NOT flagged
     * `is_investor` (build spec: "even a full-staff emergency broadcast
     * still only pushes to the agent subset"; "Investors never receive push
     * at all — no mobile app presence"). The is_investor exclusion is
     * applied unconditionally, across every broadcast_scope branch below —
     * not just the 'role'='investor' case — since an investor genuinely has
     * no mobile app installed regardless of how a given notification's
     * audience happens to be addressed; a directly `user`-targeted or
     * `role`='agent' notification must not push to them either, on the
     * (currently hypothetical, but not assumed away) chance a tenant_users
     * row is ever both role='agent' and is_investor=true.
     *
     * Beyond that fixed floor, this narrows further by $notification's own
     * audience shape, mirroring App\Models\Notification::matchesAudience()
     * with $role fixed to 'agent' and $isInvestor fixed to false:
     *
     *   - broadcast_scope='all': every non-investor agent matches, no
     *     further filter.
     *   - broadcast_scope='user': only the one targeted user_id, if they
     *     happen to hold the agent role (a targeted non-agent user never
     *     gets a push at all, by design).
     *   - broadcast_scope='role': recipient_role='agent' matches every
     *     non-investor agent (already filtered); any other recipient_role
     *     value (including 'investor' — see rbac-permissions.md section 7)
     *     matches no agent at all.
     *
     * @return Collection<int, int>
     */
    private function agentUserIdsInAudience(Notification $notification): Collection
    {
        $query = TenantUser::query()->where('role', 'agent')->where('is_investor', false);

        match ($notification->broadcast_scope) {
            'all' => null,
            'user' => $query->where('user_id', $notification->recipient_user_id),
            'role' => match ($notification->recipient_role) {
                'agent' => null,
                default => $query->whereRaw('1 = 0'),
            },
            default => $query->whereRaw('1 = 0'),
        };

        return $query->pluck('user_id');
    }
}
