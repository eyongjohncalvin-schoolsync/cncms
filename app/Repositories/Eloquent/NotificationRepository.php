<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NotificationRepository implements NotificationRepositoryInterface
{
    public function create(array $attributes): Notification
    {
        return Notification::query()->create($attributes);
    }

    public function recentForUser(int $userId, string $role, bool $isInvestor, int $limit): Collection
    {
        return $this->audienceScope(Notification::query(), $userId, $role, $isInvestor)
            ->addSelect([
                'recipient_read_at' => NotificationRecipient::query()
                    ->select('read_at')
                    ->whereColumn('notification_id', 'notifications.id')
                    ->where('user_id', $userId)
                    ->limit(1),
                'recipient_acknowledged_at' => NotificationRecipient::query()
                    ->select('acknowledged_at')
                    ->whereColumn('notification_id', 'notifications.id')
                    ->where('user_id', $userId)
                    ->limit(1),
            ])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function unreadCountForUser(int $userId, string $role, bool $isInvestor): int
    {
        return $this->audienceScope(Notification::query(), $userId, $role, $isInvestor)
            ->whereDoesntHave('recipients', fn (Builder $q) => $q->where('user_id', $userId))
            ->count();
    }

    public function unacknowledgedEmergenciesForUser(int $userId, string $role, bool $isInvestor): Collection
    {
        return $this->audienceScope(Notification::query(), $userId, $role, $isInvestor)
            ->where('severity', 'emergency')
            ->where(function (Builder $query) use ($userId) {
                $query->whereDoesntHave('recipients', fn (Builder $q) => $q->where('user_id', $userId))
                    ->orWhereHas('recipients', fn (Builder $q) => $q->where('user_id', $userId)->whereNull('acknowledged_at'));
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function markRead(Notification $notification, int $userId): void
    {
        $recipient = NotificationRecipient::query()->firstOrNew([
            'notification_id' => $notification->id,
            'user_id' => $userId,
        ]);

        if ($recipient->read_at === null) {
            $recipient->read_at = now();
        }

        $recipient->save();
    }

    public function markAllReadForUser(int $userId, string $role, bool $isInvestor): int
    {
        $unreadIds = $this->audienceScope(Notification::query(), $userId, $role, $isInvestor)
            ->whereDoesntHave('recipients', fn (Builder $q) => $q->where('user_id', $userId))
            ->pluck('id');

        if ($unreadIds->isEmpty()) {
            return 0;
        }

        $now = now();

        NotificationRecipient::query()->insert(
            $unreadIds->map(fn (int $id): array => [
                'notification_id' => $id,
                'user_id' => $userId,
                'read_at' => $now,
                'acknowledged_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        return $unreadIds->count();
    }

    public function acknowledge(Notification $notification, int $userId): void
    {
        $recipient = NotificationRecipient::query()->firstOrNew([
            'notification_id' => $notification->id,
            'user_id' => $userId,
        ]);

        if ($recipient->acknowledged_at === null) {
            $recipient->acknowledged_at = now();
        }

        $recipient->save();
    }

    /**
     * Every notification whose audience includes this user: broadcast to
     * everyone, broadcast directly to them, or broadcast to a role they
     * hold — including the investor special case (recipient_role =
     * 'investor' matched against $isInvestor, not against $role, since
     * there is no 'investor' value in the tenant_users.role enum — see
     * rbac-permissions.md section 7 and App\Models\Notification::
     * matchesAudience(), which this mirrors at the query level).
     */
    private function audienceScope(Builder $query, int $userId, string $role, bool $isInvestor): Builder
    {
        return $query->where(function (Builder $q) use ($userId, $role, $isInvestor) {
            $q->where('broadcast_scope', 'all')
                ->orWhere(fn (Builder $inner) => $inner->where('broadcast_scope', 'user')->where('recipient_user_id', $userId))
                // 'investor' is excluded here even though $role itself can
                // never literally be 'investor' in practice (no such
                // tenant_users.role enum value exists) — kept explicit so
                // this stays correct even if that guarantee ever changes,
                // and to mirror App\Models\Notification::matchesAudience()
                // exactly rather than relying on an assumption from outside
                // this class.
                ->orWhere(fn (Builder $inner) => $inner->where('broadcast_scope', 'role')->where('recipient_role', $role)->where('recipient_role', '!=', 'investor'));

            if ($isInvestor) {
                $q->orWhere(fn (Builder $inner) => $inner->where('broadcast_scope', 'role')->where('recipient_role', 'investor'));
            }
        });
    }
}
