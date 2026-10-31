<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Agent;
use App\Models\DevicePushToken;
use App\Models\Notification;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Repositories\Contracts\PushTokenRepositoryInterface;
use Illuminate\Support\Collection;

class PushTokenRepository implements PushTokenRepositoryInterface
{
    public function register(int $userId, string $deviceId, string $expoPushToken, string $platform): DevicePushToken
    {
        // The phone now belongs to this person: whoever used it before (and
        // never signed out) stops getting alerts on it.
        DevicePushToken::query()->where('expo_push_token', $expoPushToken)->where('user_id', '!=', $userId)->update(['is_valid' => false]);

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
     * For broadcasts (`all` / `role`), push-eligible recipients are always
     * `role = 'agent'` AND NOT flagged `is_investor` (build spec: "even a
     * full-staff emergency broadcast still only pushes to the agent
     * subset"). A `user`-addressed notification is the exception: it
     * reaches that one person's phone whatever their role, investors
     * included — they approve stakeholder vouchers from the app now.
     *
     * Beyond that fixed floor, this narrows further by $notification's own
     * audience shape, mirroring App\Models\Notification::matchesAudience()
     * with $role fixed to 'agent' and $isInvestor fixed to false:
     *
     *   - broadcast_scope='all': every non-investor agent matches, no
     *     further filter.
     *   - broadcast_scope='user': the one targeted user, ANY role (owner,
     *     2026-10-31: managers act from the phone now) — see the method.
     *   - broadcast_scope='role': recipient_role='agent' matches every
     *     non-investor agent (already filtered); any other recipient_role
     *     value (including 'investor' — see rbac-permissions.md section 7)
     *     matches no agent at all.
     *
     * @return Collection<int, int>
     */
    private function agentUserIdsInAudience(Notification $notification): Collection
    {
        // A notification addressed to one person reaches that person's phone
        // whatever their role — the manager inbox's alerts, a technician's
        // assignment (docs/plans/mobile-manager-app.md, wave 2, superseding
        // the agents-only rule above for `user` scope). Broadcasts to a
        // role or to everyone keep reaching agents only.
        if ($notification->broadcast_scope === 'user') {
            return collect($notification->recipient_user_id !== null ? [(int) $notification->recipient_user_id] : []);
        }

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

        // Branches v2 D12: a notification about one branch only reaches that
        // branch's agents (an agent's branch is their zone's).
        if ($notification->branch_id !== null) {
            $query->whereIn('user_id', Agent::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $notification->branch_id)->whereNotNull('user_id')->select('user_id'));
        }

        return $query->pluck('user_id');
    }
}
