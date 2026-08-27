<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Notification;
use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP coverage of App\Models\Notification::matchesAudience() — the
 * single source of truth App\Policies\NotificationPolicy and
 * App\Repositories\Eloquent\NotificationRepository::audienceScope() must
 * stay in sync with (see that method's own doc comment).
 *
 * Deliberately never persists a Notification: broadcast_scope = 'user'
 * with an arbitrary recipient_user_id that belongs to no real row is
 * exactly the "not addressed to me" case this needs to prove, and doing
 * it against an in-memory model sidesteps the cross-schema FK on
 * notifications.recipient_user_id entirely (see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles's doc comment for
 * why a second, freshly-created central user row can't cheaply be made
 * visible to the tenant connection's FK check in a Feature test) — this
 * is the one audience branch that genuinely needs a mismatched
 * recipient_user_id, so it's covered here instead.
 */
class NotificationAudienceTest extends TestCase
{
    public function test_broadcast_to_all_matches_every_user(): void
    {
        $notification = new Notification(['broadcast_scope' => 'all']);

        $this->assertTrue($notification->matchesAudience(1, 'worker', false));
        $this->assertTrue($notification->matchesAudience(999, 'super', true));
    }

    public function test_broadcast_to_user_matches_only_that_user_id(): void
    {
        $notification = new Notification(['broadcast_scope' => 'user', 'recipient_user_id' => 42]);

        $this->assertTrue($notification->matchesAudience(42, 'manager', false));
        $this->assertFalse($notification->matchesAudience(43, 'manager', false));
    }

    public function test_broadcast_to_role_matches_only_that_role(): void
    {
        $notification = new Notification(['broadcast_scope' => 'role', 'recipient_role' => 'manager']);

        $this->assertTrue($notification->matchesAudience(1, 'manager', false));
        $this->assertFalse($notification->matchesAudience(1, 'agent', false));
    }

    /**
     * Investors are addressed via recipient_role = 'investor', matched
     * against the separate $isInvestor flag rather than the $role string
     * itself — there is no 'investor' value in the tenant_users.role enum
     * (rbac-permissions.md section 7).
     */
    public function test_broadcast_to_investor_role_matches_only_investor_flagged_users_regardless_of_role(): void
    {
        $notification = new Notification(['broadcast_scope' => 'role', 'recipient_role' => 'investor']);

        $this->assertTrue($notification->matchesAudience(1, 'worker', true));
        $this->assertFalse($notification->matchesAudience(1, 'worker', false));
        // A literal role value of 'investor' is not itself sufficient —
        // matching is only ever driven by $isInvestor.
        $this->assertFalse($notification->matchesAudience(1, 'investor', false));
    }
}
