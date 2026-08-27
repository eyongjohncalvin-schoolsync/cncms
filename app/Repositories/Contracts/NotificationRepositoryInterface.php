<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Notification;
use Illuminate\Support\Collection;

interface NotificationRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes  Shaped by App\DataTransferObjects\NotificationData::toAttributes().
     */
    public function create(array $attributes): Notification;

    /**
     * Most recent notifications in $userId's audience, newest first, each
     * carrying that user's own read_at/acknowledged_at as extra selected
     * attributes (`recipient_read_at`/`recipient_acknowledged_at` — see
     * App\Http\Resources\NotificationResource, which reads them back off
     * the model). Powers the bell dropdown.
     *
     * @return Collection<int, Notification>
     */
    public function recentForUser(int $userId, string $role, bool $isInvestor, int $limit): Collection;

    /**
     * Count of notifications in $userId's audience with no
     * notification_recipients row for them yet at all — the literal
     * "unread" formula from in-app-notifications.md section 3. Powers the
     * bell badge.
     */
    public function unreadCountForUser(int $userId, string $role, bool $isInvestor): int;

    /**
     * severity='emergency' notifications in $userId's audience that are
     * not yet acknowledged by them (no recipient row, or a recipient row
     * with acknowledged_at still null) — drives the persistent emergency
     * banner.
     *
     * @return Collection<int, Notification>
     */
    public function unacknowledgedEmergenciesForUser(int $userId, string $role, bool $isInvestor): Collection;

    /**
     * Sets read_at the first time this is called for a given
     * (notification, user) pair — a no-op on subsequent calls, preserving
     * the original read time.
     */
    public function markRead(Notification $notification, int $userId): void;

    /**
     * Bulk-inserts fresh, already-read recipient rows for every
     * currently-unread notification in $userId's audience. Returns how
     * many were newly marked.
     */
    public function markAllReadForUser(int $userId, string $role, bool $isInvestor): int;

    /**
     * Sets acknowledged_at the first time this is called for a given
     * (notification, user) pair — independent of read_at (see
     * App\Models\NotificationRecipient's class doc).
     */
    public function acknowledge(Notification $notification, int $userId): void;
}
