<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real "swecom" tenant, same transaction/role-switching
 * strategy as tests/Feature/Web/ResourceTest.php (see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles's doc comment for
 * why a single reusable seeded owner row is flipped between roles rather
 * than creating fresh TenantUser rows per test).
 *
 * The `notifications` shared Inertia prop (App\Http\Middleware\
 * HandleInertiaRequests) is exercised via GET /dashboard — the simplest
 * tenant-scoped page with no role restriction of its own, so it's a
 * neutral surface for asserting the prop across every role. Unread/
 * emergency counts are asserted as deltas over a freshly-read baseline
 * (mirroring tests/Feature/Api/ManuscriptTest.php's own convention) rather
 * than assumed-zero, since this runs against a real, possibly non-empty
 * tenant schema.
 */
class NotificationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function actingAsRole(string $role, bool $isInvestor = false): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'is_investor' => $isInvestor]);

        $this->actingAs($user);

        return $user;
    }

    private function seededUserId(): int
    {
        return User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail()->id;
    }

    private function unreadCount(): int
    {
        $count = null;

        $this->get('/dashboard')->assertOk()->assertInertia(function (Assert $page) use (&$count) {
            $count = $page->toArray()['props']['notifications']['unread_count'];
        });

        return $count;
    }

    private function emergencyCount(): int
    {
        $count = null;

        $this->get('/dashboard')->assertOk()->assertInertia(function (Assert $page) use (&$count) {
            $count = count($page->toArray()['props']['notifications']['emergency']);
        });

        return $count;
    }

    public function test_broadcast_to_role_is_visible_only_to_users_holding_that_role(): void
    {
        $this->actingAsRole('agent');
        $baseline = $this->unreadCount();

        app(NotificationService::class)->broadcastToRole('manager', 'test.role_broadcast', 'info', 'Manager notice', 'Body text');

        // Still acting as 'agent' — a role-'manager' broadcast must not
        // appear for them.
        $this->assertSame($baseline, $this->unreadCount());

        // Flip the same seeded user to 'manager' — now it must appear,
        // and flipping back to 'agent' makes it disappear again (proves
        // this is genuinely role-scoped, not just "any user sees it").
        $this->actingAsRole('manager');
        $this->assertSame($baseline + 1, $this->unreadCount());

        $this->actingAsRole('agent');
        $this->assertSame($baseline, $this->unreadCount());
    }

    /**
     * The core lazy-fan-out claim (in-app-notifications.md section 3): a
     * user who only gains a role AFTER a role broadcast fires still sees
     * it, with zero backfill write. Broadcasting while the seeded user is
     * 'worker' (so it's genuinely not addressed to them yet), then
     * promoting them to 'admin' afterwards, is exactly that scenario.
     */
    public function test_a_user_promoted_after_a_role_broadcast_still_sees_it_without_backfill(): void
    {
        $this->actingAsRole('worker');
        $baseline = $this->unreadCount();

        $notification = app(NotificationService::class)->broadcastToRole('admin', 'test.promotion', 'info', 'Admin notice', 'Body text');

        $this->assertSame($baseline, $this->unreadCount());

        // No write to the notification or to notification_recipients
        // happens here — only the seeded user's own role changes.
        $this->actingAsRole('admin');

        $this->assertSame($baseline + 1, $this->unreadCount());
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_id' => $notification->id,
        ], 'tenant');
    }

    /**
     * The "not addressed to someone else" half of broadcast_scope='user'
     * is covered by the pure-PHP
     * Tests\Unit\NotificationAudienceTest::test_broadcast_to_user_matches_only_that_user_id()
     * instead of here — a real, distinct second central `users` row
     * can't cheaply be made visible to the `tenant` connection's
     * cross-schema FK check inside this test's own transaction (see
     * Tests\Feature\Api\Concerns\InteractsWithTenantRoles's doc comment).
     * This test covers the positive, real-HTTP-round-trip half: a
     * user-scoped broadcast addressed to the current user actually shows
     * up in their own feed.
     */
    public function test_broadcast_to_user_is_visible_to_that_user(): void
    {
        $me = $this->actingAsRole('manager');
        $baseline = $this->unreadCount();

        app(NotificationService::class)->broadcastToUser($me, 'test.user_broadcast', 'info', 'For me', 'Body text');
        $this->assertSame($baseline + 1, $this->unreadCount());
    }

    public function test_broadcast_to_all_is_visible_regardless_of_role(): void
    {
        $this->actingAsRole('worker');
        $baseline = $this->unreadCount();

        app(NotificationService::class)->broadcastToAll('test.all_broadcast', 'info', 'Everyone notice', 'Body text');

        $this->assertSame($baseline + 1, $this->unreadCount());
    }

    /**
     * Investors are addressed via recipient_role = 'investor', matched
     * against tenant_users.is_investor rather than the role column (there
     * is no 'investor' role enum value — rbac-permissions.md section 7).
     */
    public function test_investor_role_broadcast_is_visible_only_to_investor_flagged_users(): void
    {
        $this->actingAsRole('worker', isInvestor: false);
        $baseline = $this->unreadCount();

        app(NotificationService::class)->broadcastToRole('investor', 'test.investor_broadcast', 'info', 'Investor notice', 'Body text');

        // 'worker' role, not investor-flagged — must not see it.
        $this->assertSame($baseline, $this->unreadCount());

        // Same seeded user, role still 'worker' but now investor-flagged —
        // must see it (is_investor is additive, independent of `role`).
        $this->actingAsRole('worker', isInvestor: true);
        $this->assertSame($baseline + 1, $this->unreadCount());
    }

    public function test_read_at_and_acknowledged_at_are_independently_settable(): void
    {
        $user = $this->actingAsRole('manager');

        $notification = app(NotificationService::class)->broadcastToUser($user, 'test.read_vs_ack', 'emergency', 'Independent state', 'Body text');

        // Acknowledge WITHOUT reading first — read_at must stay null.
        $this->post("/notifications/{$notification->uuid}/acknowledge")->assertRedirect();

        $recipient = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($recipient);
        $this->assertNotNull($recipient->acknowledged_at);
        $this->assertNull($recipient->read_at);

        // Now mark it read — acknowledged_at must be untouched.
        $acknowledgedAt = $recipient->acknowledged_at;
        $this->post("/notifications/{$notification->uuid}/read")->assertRedirect();

        $recipient->refresh();
        $this->assertNotNull($recipient->read_at);
        $this->assertTrue($recipient->acknowledged_at->equalTo($acknowledgedAt));
    }

    public function test_reading_a_notification_removes_it_from_the_unread_count(): void
    {
        $user = $this->actingAsRole('manager');
        $baseline = $this->unreadCount();

        $notification = app(NotificationService::class)->broadcastToUser($user, 'test.mark_read', 'info', 'Read me', 'Body text');
        $this->assertSame($baseline + 1, $this->unreadCount());

        $this->post("/notifications/{$notification->uuid}/read")->assertRedirect();

        $this->assertSame($baseline, $this->unreadCount());
    }

    public function test_mark_all_read_clears_every_currently_unread_notification_in_the_users_audience(): void
    {
        $user = $this->actingAsRole('manager');
        $baseline = $this->unreadCount();

        app(NotificationService::class)->broadcastToUser($user, 'test.bulk_a', 'info', 'A', 'Body');
        app(NotificationService::class)->broadcastToRole('manager', 'test.bulk_b', 'info', 'B', 'Body');
        $this->assertSame($baseline + 2, $this->unreadCount());

        $this->post('/notifications/read-all')->assertRedirect();

        $this->assertSame(0, $this->unreadCount());
    }

    public function test_acknowledging_an_emergency_notification_removes_it_from_the_banner(): void
    {
        $user = $this->actingAsRole('manager');
        $baseline = $this->emergencyCount();

        $notification = app(NotificationService::class)->broadcastToUser($user, 'test.emergency', 'emergency', 'Critical', 'Act now');
        $this->assertSame($baseline + 1, $this->emergencyCount());

        $this->post("/notifications/{$notification->uuid}/acknowledge")->assertRedirect();

        $this->assertSame($baseline, $this->emergencyCount());
    }

    public function test_a_user_cannot_act_on_a_notification_outside_their_own_audience(): void
    {
        // Role-scoped to 'super' while acting as 'manager' — outside this
        // user's audience the same way a different broadcast_scope='user'
        // recipient would be (see test_broadcast_to_user_is_visible_to_that_user()'s
        // doc comment for why the user-scope variant of this isn't
        // exercised via a real second HTTP-round-trip user here).
        $notification = app(NotificationService::class)->broadcastToRole('super', 'test.not_mine', 'info', 'Not yours', 'Body');

        $this->actingAsRole('manager');

        $this->post("/notifications/{$notification->uuid}/read")->assertForbidden();
        $this->post("/notifications/{$notification->uuid}/acknowledge")->assertForbidden();
    }
}
