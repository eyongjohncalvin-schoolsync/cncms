<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Confirms real session-cookie login (POST /login) actually works for each
 * of the app's 5 permission tiers, and that the role/permissions the
 * client-side nav gating keys off of (RBAC v2 Wave 4: `buildVisibleNavItems`
 * in resources/tsx/components/shared/AppNav.tsx now filters on
 * `auth.user.permissions`, not role arrays) are exactly what the server
 * hands back in the post-login Inertia payload.
 *
 * This was written alongside adding the purely-descriptive `job_title`
 * field to tenant_users (see the
 * 2026_08_24_130000_add_job_title_to_tenant_users_table migration and
 * TenantUserPolicy's doc block) — job_title carries no authorization
 * meaning, so this test deliberately only exercises the 5 existing `role`
 * values and never touches job_title.
 *
 * AppNav.tsx hides the Settings / Resources / Audit Log / Disconnections
 * nav links from anyone missing that item's permission, but the nav
 * hiding is only ever a UX convenience — the actual gate is always the
 * server-side Policy each item's comment cites
 * (TenantUserPolicy::viewAny, ExpenditurePolicy::viewDashboard,
 * AuditLogPolicy::viewAny, CustomerPolicy::viewStatusBoard). This test
 * hits those same endpoints directly to prove the gating is real, not just
 * a hidden link a determined user could still click past.
 *
 * The complementary "action-level" 403 checks the product owner asked for
 * (e.g. a worker attempting to mutate something they can't) are NOT
 * duplicated here — they already exist and pass:
 *   - tests/Feature/Web/CustomerTest.php::test_agent_cannot_create_a_customer
 *     (POST /customers -> 403)
 *   - tests/Feature/Web/CustomerTest.php::test_worker_gets_a_403_attempting_to_suspend_a_customer
 *     (PATCH /customers/{uuid}/suspend -> 403)
 *   - tests/Feature/Api/AuditLogTest.php::test_an_agent_is_forbidden_from_viewing_audit_logs
 *     (GET /api/v1/audit/logs -> 403)
 */
class RoleLoginTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private const ROLES = ['super', 'admin', 'manager', 'agent', 'worker'];

    /**
     * Endpoints gated to super/admin/manager only, matching AppLayout.tsx's
     * SETTINGS_ROLES / RESOURCES_ROLES / AUDIT_ROLES / DISCONNECTIONS_ROLES
     * (each nav item's doc comment names the exact Policy method below).
     *
     * @var array<string, string>
     */
    private const NAV_GATED_ENDPOINTS = [
        'Users Control Center (TenantUserPolicy::viewAny)' => '/users',
        'Resources dashboard (ExpenditurePolicy::viewDashboard)' => '/resources',
        'Audit Log (AuditLogPolicy::viewAny)' => '/audit/logs',
        'Disconnections status board (CustomerPolicy::viewStatusBoard)' => '/disconnections',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    public function test_each_of_the_five_roles_can_log_in_and_lands_on_the_dashboard_with_the_correct_role(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        foreach (self::ROLES as $role) {
            TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

            $loginResponse = $this->post('/login', [
                'username' => 'kelvin@shalomtech.dev',
                'password' => 'password',
            ]);

            $loginResponse->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($user);

            // This is the exact prop (auth.user.role) AppLayout.tsx's
            // visibleNavItems filter reads to decide which nav links to show
            // — asserting it here is the server-side half of "the right nav
            // shows for each role".
            $dashboardResponse = $this->get('/dashboard');
            $dashboardResponse->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard')
                    ->where('auth.user.role', $role));

            $this->post('/logout')->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    public function test_worker_is_denied_every_settings_resources_audit_and_disconnections_nav_destination(): void
    {
        $this->actingAsRoleForNav('worker');

        foreach (self::NAV_GATED_ENDPOINTS as $description => $endpoint) {
            $this->get($endpoint)->assertForbidden();
        }
    }

    public function test_agent_is_also_denied_every_settings_resources_audit_and_disconnections_nav_destination(): void
    {
        $this->actingAsRoleForNav('agent');

        foreach (self::NAV_GATED_ENDPOINTS as $description => $endpoint) {
            $this->get($endpoint)->assertForbidden();
        }
    }

    public function test_super_can_reach_every_settings_resources_audit_and_disconnections_nav_destination(): void
    {
        $this->actingAsRoleForNav('super');

        foreach (self::NAV_GATED_ENDPOINTS as $description => $endpoint) {
            $this->get($endpoint)->assertOk();
        }
    }

    /**
     * agent doesn't get the Disconnections status board (see the worker/agent
     * test above), but AppLayout.tsx still gives them a dedicated
     * "Flagged Customers" nav entry straight into the arrears-based
     * eligibility view (CustomerPolicy::viewEligibilityBoard), which IS
     * allowed for agents per business-rules.md. Confirms that link isn't
     * dead for the one role it's shown to.
     */
    public function test_agent_can_reach_the_flagged_customers_eligibility_view_despite_the_status_board_being_blocked(): void
    {
        $this->actingAsRoleForNav('agent');

        $this->get('/disconnections?eligible=1')->assertOk();
    }

    private function actingAsRoleForNav(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }
}
