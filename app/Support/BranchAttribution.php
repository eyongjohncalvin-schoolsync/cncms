<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use App\Models\TenantUser;
use App\Models\User;

/**
 * Which branch a person's work belongs to (docs/plans/branches-v2.md §5) —
 * derived on the server, never chosen by a request. The person's own fence
 * (TenantContext::resolve(): agents through their zone, everyone else
 * tenant_users.branch_id); an unfenced person works for headquarters.
 *
 * Costs a query or two: resolve once per request or job, never per row.
 */
final class BranchAttribution
{
    public static function forUser(User $user): ?int
    {
        return self::forUserId($user->id);
    }

    public static function forUserId(int $userId): ?int
    {
        $tenantUser = TenantUser::query()
            ->where('tenant_id', (string) tenant()?->getTenantKey())
            ->where('user_id', $userId)
            ->first();

        $fence = $tenantUser !== null ? TenantContext::resolve($tenantUser)->branchId : null;

        return $fence ?? Branch::headquartersId();
    }
}
