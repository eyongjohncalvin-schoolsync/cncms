<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Role;

/**
 * In-app notifications for the cash controls (docs/plans/cash-controls.md),
 * addressed by permission rather than by role name: every role that holds
 * $permission — plus the owner (super), who holds everything — is notified
 * through NotificationService's lazy role fan-out, the same way
 * OpeningBalanceService::notifyApprovers() reaches whoever can approve.
 */
class CashNotificationService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function toHoldersOf(
        string $permission,
        string $type,
        string $severity,
        string $title,
        string $body,
        ?string $link = null,
        ?string $sourceType = null,
        ?string $sourceUuid = null,
    ): void {
        $roles = Role::query()
            ->where('is_super', true)
            ->orWhereHas('permissions', fn ($query) => $query->where('permission', $permission))
            ->pluck('name');

        foreach ($roles as $role) {
            $this->notifications->broadcastToRole($role, $type, $severity, $title, $body, $link, $sourceType, $sourceUuid);
        }
    }
}
