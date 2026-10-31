<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * Alerts the people who can act on something (docs/plans/mobile-manager-
 * app.md, wave 2): whoever holds a permission, in the item's branch, as
 * personal notifications — so each reaches that person's phone
 * (SendPushNotificationJob pushes user-addressed notifications to any role)
 * and shows in their bell. `urgent` rings; `warning` arrives silently.
 *
 * The same people the manager inbox shows the item to: TenantContext::can()
 * per member, a branch-fenced member only for their own branch, nobody for
 * their own request. Must run inside the tenant.
 */
class ActionAlertService
{
    /** @var array<int, TenantContext> per request: one role lookup per member */
    private array $contexts = [];

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Active members holding $permission who may act in $branchId (null =
     * company-wide item: everyone with the permission). $officeOnly leaves
     * out collectors fenced to their own zones.
     *
     * @return Collection<int, User>
     */
    public function deciders(string $permission, ?int $branchId = null, ?int $exceptUserId = null, bool $officeOnly = false): Collection
    {
        $members = TenantUser::query()
            ->where('tenant_id', (string) tenant()?->getTenantKey())
            ->get()
            ->filter(function (TenantUser $member) use ($permission, $branchId, $exceptUserId, $officeOnly): bool {
                if ($member->user_id === $exceptUserId) {
                    return false;
                }

                $context = $this->contexts[$member->id] ??= TenantContext::resolve($member);

                return $context->can($permission)
                    && ! ($officeOnly && $context->zoneIds !== null)
                    && ($context->branchId === null || $branchId === null || $context->branchId === $branchId);
            });

        return User::query()->whereIn('id', $members->pluck('user_id'))->where('status', 'active')->get();
    }

    /**
     * Owners (super) — who an untouched item escalates to.
     *
     * @return Collection<int, User>
     */
    public function owners(): Collection
    {
        return $this->withRoles(['super']);
    }

    /**
     * Active members holding one of $roles, minus $exceptUserIds — for a
     * stage a permission alone doesn't describe (an arrears adjustment's
     * second approval: owner or admin, never the requester or first approver).
     *
     * @param  list<string>  $roles
     * @param  list<int>  $exceptUserIds
     * @return Collection<int, User>
     */
    public function withRoles(array $roles, array $exceptUserIds = []): Collection
    {
        $ids = TenantUser::query()
            ->where('tenant_id', (string) tenant()?->getTenantKey())
            ->whereIn('role', $roles)
            ->whereNotIn('user_id', $exceptUserIds)
            ->pluck('user_id');

        return User::query()->whereIn('id', $ids)->where('status', 'active')->get();
    }

    /**
     * @param  iterable<User>  $users
     * @param  'urgent'|'warning'  $severity
     */
    public function alert(iterable $users, string $type, string $severity, string $title, string $body, string $link, string $sourceType, string $sourceUuid, ?int $branchId = null): int
    {
        $sent = 0;

        foreach ($users as $user) {
            $this->notifications->broadcastToUser($user, $type, $severity, $title, $body, $link, $sourceType, $sourceUuid, $branchId);
            $sent++;
        }

        return $sent;
    }
}
