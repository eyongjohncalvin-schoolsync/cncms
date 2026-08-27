<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\NotificationData;
use App\Http\Resources\NotificationResource;
use App\Jobs\SendPushNotificationJob;
use App\Models\Notification;
use App\Models\User;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use App\Support\TenantContext;

/**
 * Public API for the in-app notification system (in-app-notifications.md).
 * This is the client surface other backend features — most notably the
 * Complaint Desk (references/complaint-desk.md) — call directly as plain
 * PHP, e.g.:
 *
 *   $notifications->broadcastToUser($assignee, 'complaint.assigned', 'info', ...);
 *   $notifications->broadcastToRole('manager', 'complaint.escalated', 'urgent', ...);
 *   $notifications->broadcastToRole('investor', 'complaint.escalated', 'emergency', ...);
 *   $notifications->broadcastToAll('complaint.critical', 'emergency', ...);
 *
 * None of those calls require the caller to know anything about the
 * notifications/notification_recipients schema, the lazy fan-out
 * mechanism, or the investor special case — that's all encapsulated
 * below and in App\Repositories\Eloquent\NotificationRepository.
 *
 * The remaining methods (feedForUser/markRead/markAllRead/acknowledge)
 * are the read/write side used by App\Http\Controllers\
 * NotificationController and App\Http\Middleware\HandleInertiaRequests —
 * not expected to be called by other features.
 */
class NotificationService
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notifications,
    ) {}

    /**
     * The one choke point every broadcastTo*() helper below funnels
     * through — so it's also the one place the mobile push "fast channel"
     * (mobile-push-notifications build notes) is wired in, as a direct call
     * rather than a model observer (this codebase's established preference
     * for explicit call-site wiring over implicit event listeners).
     * Unconditional dispatch: SendPushNotificationJob itself is the single
     * place that decides whether this particular notification is actually
     * push-eligible (severity, audience) — see its class doc — so this
     * stays a plain one-line hook regardless of what kind of notification
     * was just created.
     */
    public function create(NotificationData $data): Notification
    {
        $notification = $this->notifications->create($data->toAttributes());

        SendPushNotificationJob::dispatch($notification->uuid);

        return $notification;
    }

    /**
     * Targets exactly one user, regardless of their role.
     */
    public function broadcastToUser(
        User $user,
        string $type,
        string $severity,
        string $title,
        string $body,
        ?string $link = null,
        ?string $sourceType = null,
        ?string $sourceUuid = null,
    ): Notification {
        return $this->create(new NotificationData(
            type: $type,
            severity: $severity,
            title: $title,
            body: $body,
            link: $link,
            sourceType: $sourceType,
            sourceUuid: $sourceUuid,
            broadcastScope: 'user',
            recipientUserId: $user->id,
        ));
    }

    /**
     * Targets every tenant user currently holding $role — including a
     * user who is only promoted into (or hired/added into) that role
     * AFTER this call, since fan-out is computed lazily at read time, not
     * written eagerly here (in-app-notifications.md section 3). Pass
     * 'investor' to target the investor tier (tenant_users.is_investor —
     * see rbac-permissions.md section 7), not a `role` column value.
     *
     * $role = 'all' is accepted as a convenience alias for
     * broadcastToAll(), since callers occasionally reach for
     * broadcastToRole('all', ...) — it's handled identically either way.
     */
    public function broadcastToRole(
        string $role,
        string $type,
        string $severity,
        string $title,
        string $body,
        ?string $link = null,
        ?string $sourceType = null,
        ?string $sourceUuid = null,
    ): Notification {
        if ($role === 'all') {
            return $this->broadcastToAll($type, $severity, $title, $body, $link, $sourceType, $sourceUuid);
        }

        return $this->create(new NotificationData(
            type: $type,
            severity: $severity,
            title: $title,
            body: $body,
            link: $link,
            sourceType: $sourceType,
            sourceUuid: $sourceUuid,
            broadcastScope: 'role',
            recipientRole: $role,
        ));
    }

    /**
     * Targets every tenant user, regardless of role.
     */
    public function broadcastToAll(
        string $type,
        string $severity,
        string $title,
        string $body,
        ?string $link = null,
        ?string $sourceType = null,
        ?string $sourceUuid = null,
    ): Notification {
        return $this->create(new NotificationData(
            type: $type,
            severity: $severity,
            title: $title,
            body: $body,
            link: $link,
            sourceType: $sourceType,
            sourceUuid: $sourceUuid,
            broadcastScope: 'all',
        ));
    }

    /**
     * Everything App\Http\Middleware\HandleInertiaRequests' shared
     * `notifications` prop and the bell dropdown need in one call: the
     * recent list (with this user's own read/acknowledge state attached),
     * the unread badge count, and any unacknowledged emergency
     * notifications for the persistent banner.
     *
     * @return array{items: array<int, array<string, mixed>>, unread_count: int, emergency: array<int, array<string, mixed>>}
     */
    public function feedForUser(User $user, TenantContext $context, int $limit = 20): array
    {
        $isInvestor = (bool) $context->tenantUser->is_investor;

        $recent = $this->notifications->recentForUser($user->id, $context->role, $isInvestor, $limit);
        $emergencies = $this->notifications->unacknowledgedEmergenciesForUser($user->id, $context->role, $isInvestor);

        return [
            'items' => NotificationResource::collection($recent)->resolve(),
            'unread_count' => $this->notifications->unreadCountForUser($user->id, $context->role, $isInvestor),
            'emergency' => NotificationResource::collection($emergencies)->resolve(),
        ];
    }

    public function markRead(Notification $notification, User $user): void
    {
        $this->notifications->markRead($notification, $user->id);
    }

    public function markAllRead(User $user, TenantContext $context): void
    {
        $this->notifications->markAllReadForUser($user->id, $context->role, (bool) $context->tenantUser->is_investor);
    }

    public function acknowledge(Notification $notification, User $user): void
    {
        $this->notifications->acknowledge($notification, $user->id);
    }
}
