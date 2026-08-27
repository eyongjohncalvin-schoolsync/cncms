<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Unlike every other Policy in this app, there is no role restriction here
 * — every authenticated tenant user (including `worker`, and investors)
 * can view their own notification feed; v1 is "everyone gets everything
 * relevant to their role" (in-app-notifications.md section 7), not a
 * gated feature. What IS gated per-notification is view()/acknowledge():
 * a user may only act on a notification that is actually in their
 * audience (Notification::matchesAudience()), so one user can never mark
 * another's role/user-targeted notification read or acknowledge it just
 * by guessing its uuid.
 *
 * There is deliberately no create() ability — notifications are only ever
 * created by other backend code calling App\Services\NotificationService
 * directly (e.g. the Complaint Desk feature), never via an HTTP endpoint.
 */
class NotificationPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Notification $notification): bool
    {
        return $notification->matchesAudience($user->id, $this->context->role, (bool) $this->context->tenantUser->is_investor);
    }

    public function acknowledge(User $user, Notification $notification): bool
    {
        return $this->view($user, $notification);
    }
}
