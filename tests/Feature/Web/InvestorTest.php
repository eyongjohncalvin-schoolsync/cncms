<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Coverage for the Investor tier — see references/rbac-permissions.md
 * section 7, App\Policies\ReportPolicy::view()'s doc comment, and
 * App\Http\Middleware\HandleInertiaRequests::share()'s `is_investor` key.
 *
 * Follows tests/Feature/Web/ReportTest.php's actingAsRole() convention and
 * tests/Feature/Web/RoleLoginTest.php's real POST /login pattern (both via
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles's "reuse the seeded
 * owner, flip a column" approach — see that trait's doc comment for why a
 * freshly-inserted TenantUser can't be used here).
 *
 * The central thesis under test: `tenant_users.is_investor` is a single,
 * additive OR on ReportPolicy::view() and nothing else — an investor whose
 * `role` sits at the recommended `worker` floor must be denied everywhere
 * a plain worker is denied, and the flag must never widen access beyond
 * viewing /reports.
 */
class InvestorTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    /**
     * Endpoints a plain `worker` (and therefore a worker-role investor,
     * since is_investor must never widen anything but ReportPolicy::view())
     * must be forbidden from, regardless of the is_investor flag. Mirrors
     * RoleLoginTest::NAV_GATED_ENDPOINTS's approach but deliberately wider —
     * a real negative-access sweep across every admin/office-only area,
     * not just the four nav-gated ones that test already covers.
     *
     * Each entry: [http method, path, Policy method it's actually gated by].
     * Index/list routes that are open to EVERY role by design (e.g.
     * CustomerPolicy::viewAny/BranchPolicy::viewAny/ZonePolicy::viewAny/
     * AgentPolicy::viewAny all return `true` unconditionally — "everyone
     * with tenant access can view") are deliberately excluded here and
     * covered instead by test_investor_flag_does_not_widen_view_only_routes()
     * below, which asserts they stay at exactly baseline-worker access
     * (200, same as any worker) rather than being forbidden — asserting
     * 403 on them would be asserting something false about this app's
     * actual authorization design, not a real security gap.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const FORBIDDEN_ENDPOINTS = [
        ['get', '/resources', 'ExpenditurePolicy::viewDashboard'],
        ['get', '/resources/expenditures/create', 'ExpenditurePolicy::create'],
        ['post', '/resources/categories', 'ExpenseCategoryPolicy::create'],
        ['get', '/users', 'TenantUserPolicy::viewAny'],
        ['get', '/settings/command-runs', 'CommandRunPolicy::viewAny'],
        ['patch', '/settings/company', 'UpdateCompanyRequest::authorize (CompanyPolicy::update)'],
        ['get', '/audit/logs', 'AuditLogPolicy::viewAny'],
        ['get', '/branches/create', 'BranchPolicy::create'],
        ['post', '/branches', 'BranchPolicy::create'],
        ['get', '/customers/create', 'CustomerPolicy::create'],
        ['post', '/customers', 'CustomerPolicy::create'],
        ['get', '/manuscripts', 'ManuscriptPolicy::viewAny (worker excluded)'],
        ['get', '/zones/create', 'ZonePolicy::create'],
        ['get', '/agents/create', 'AgentPolicy::create'],
        ['get', '/disconnections', 'CustomerPolicy::viewStatusBoard'],
        ['get', '/payments/create', 'PaymentPolicy::create (can_record_payments is false)'],
        ['post', '/payments', 'PaymentPolicy::create (can_record_payments is false)'],
        ['get', '/reports/export', 'ReportPolicy::export (investors can view, never export)'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    /**
     * Grants/revokes is_investor on the seeded owner (kelvin@shalomtech.dev)
     * and sets their role — 'worker' by default, matching §7's recommended
     * default ("role should default to 'worker' as a defensive backstop").
     * Same "reuse the committed seeded owner, flip a column" strategy as
     * ReportTest::actingAsRole() / RoleLoginTest, for the same cross-
     * connection-visibility reason (see InteractsWithTenantRoles's doc
     * comment).
     */
    private function actingAsInvestor(bool $isInvestor = true, string $role = 'worker'): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        TenantUser::query()->where('user_id', $user->id)->update([
            'role' => $role,
            'is_investor' => $isInvestor,
            'branch_id' => null,
        ]);

        $this->actingAs($user);

        return $user;
    }

    // -----------------------------------------------------------------
    // Login flow — must be the exact same /login form/flow as every other
    // role, per §7's owner framing ("same login form... never a separate
    // portal or credential system"). No special route, no special redirect.
    // -----------------------------------------------------------------

    public function test_an_investor_logs_in_through_the_exact_same_login_endpoint_as_any_other_role(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        TenantUser::query()->where('user_id', $user->id)->update([
            'role' => 'worker',
            'is_investor' => true,
            'branch_id' => null,
        ]);

        $loginResponse = $this->post('/login', [
            'username' => 'kelvin@shalomtech.dev',
            'password' => 'password',
        ]);

        // Same redirect target as every other role (RoleLoginTest asserts
        // this identical '/dashboard' redirect for all 5 roles) — no
        // investor-specific post-login routing exists or is introduced.
        $loginResponse->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        // The is_investor flag is visible on the very next Inertia
        // response, in the exact same shared `auth.user` shape every other
        // role gets (App\Http\Middleware\HandleInertiaRequests::share()) —
        // proving this is ordinary post-login session state, not a
        // separate credential/portal mechanism.
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('auth.user.role', 'worker')
                ->where('auth.user.is_investor', true));
    }

    // -----------------------------------------------------------------
    // Positive: can view reports, cannot export.
    // -----------------------------------------------------------------

    public function test_investor_can_reach_and_view_reports(): void
    {
        $this->actingAsInvestor();

        $response = $this->get('/reports');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('auth.user.is_investor', true)
            // super/admin/manager only, per ReportPolicy::export() —
            // untouched by the is_investor OR, so this must stay false.
            ->where('can_export', false));
    }

    public function test_investor_cannot_export_reports(): void
    {
        $this->actingAsInvestor();

        $this->get('/reports/export')->assertForbidden();
    }

    public function test_a_plain_worker_without_the_flag_is_still_forbidden_from_reports(): void
    {
        // The exact same user/role, just without the grant — proves the
        // access genuinely comes from is_investor, not from anything else
        // about this seeded row.
        $this->actingAsInvestor(isInvestor: false);

        $this->get('/reports')->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Negative-access sweep — the flag must never widen anything beyond
    // ReportPolicy::view().
    // -----------------------------------------------------------------

    public function test_investor_negative_access_sweep_cannot_reach_any_other_admin_or_office_route(): void
    {
        $this->actingAsInvestor();

        foreach (self::FORBIDDEN_ENDPOINTS as [$method, $path, $description]) {
            /** @var TestResponse $response */
            $response = $this->{$method}($path);

            $this->assertTrue(
                $response->isForbidden(),
                "Expected 403 for {$method} {$path} ({$description}) but got {$response->getStatusCode()}.",
            );
        }
    }

    /**
     * View-only routes that are open to EVERY role by this app's actual
     * design (CustomerPolicy/BranchPolicy/ZonePolicy/AgentPolicy's
     * viewAny() all return `true` unconditionally) must stay at exactly
     * that same baseline for an investor — is_investor must not add
     * anything here, but it must not silently take anything away either.
     * Confirmed by diffing against the identical routes with is_investor
     * explicitly false: the response must be identical either way.
     *
     * Cache::flush() between the two passes — same rationale as
     * ReportTest::setUp()'s identical call: the 'array' cache store keeps
     * list results (e.g. AgentService::list()'s Cache::remember()) by
     * object reference rather than a fresh copy per read, and
     * AgentController::index()'s ->through() mutates that cached
     * paginator's collection in place. Hitting the exact same /agents
     * list twice in one process without flushing would have the second
     * call observe the first call's already-through()'d (Agent -> array)
     * collection and blow up re-transforming arrays as if they were
     * models — a pre-existing quirk of that unrelated caching code, not
     * something this test is trying to exercise.
     */
    public function test_investor_flag_does_not_change_the_baseline_view_only_routes_every_worker_already_has(): void
    {
        $viewOnlyRoutes = ['/customers', '/branches', '/zones', '/agents', '/payments'];

        $this->actingAsInvestor(isInvestor: false);
        $withoutFlag = array_map(fn (string $path): int => $this->get($path)->getStatusCode(), $viewOnlyRoutes);

        Cache::flush();

        $this->actingAsInvestor(isInvestor: true);
        $withFlag = array_map(fn (string $path): int => $this->get($path)->getStatusCode(), $viewOnlyRoutes);

        $this->assertSame(
            $withoutFlag,
            $withFlag,
            'is_investor must not change the response for routes every role already has baseline view access to.',
        );

        // And that baseline is actually "allowed" (200), not coincidentally
        // both forbidden for some unrelated reason.
        foreach ($withFlag as $status) {
            $this->assertSame(200, $status);
        }
    }

    /**
     * Mirrors §7's explicit worked example: a worker-role investor keeps
     * every one of worker's normal restrictions on mutation actions across
     * those same view-only areas — the flag grants exactly one capability
     * (viewing /reports), never a general "office staff" upgrade.
     */
    public function test_worker_role_investor_still_has_workers_normal_mutation_restrictions(): void
    {
        $this->actingAsInvestor();

        $this->get('/customers/create')->assertForbidden();
        $this->get('/branches/create')->assertForbidden();
        $this->get('/zones/create')->assertForbidden();
        $this->get('/agents/create')->assertForbidden();
        $this->post('/payments')->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Admin grant/revoke UI (Users Control Center's per-user checkbox — see
    // app/Http/Controllers/UsersControlCenter/UserController.php::update()
    // and UpdateTenantUserRequest, mirroring the can_record_payments precedent).
    // -----------------------------------------------------------------

    public function test_super_can_grant_and_then_revoke_investor_status_with_the_audit_trail_stamped(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'super']);
        $this->actingAs($user);

        $tenantUser = TenantUser::query()->where('user_id', $user->id)->firstOrFail();

        $response = $this->patch("/users/{$tenantUser->id}", ['is_investor' => true]);

        $response->assertRedirect('/users');
        $response->assertSessionHas('success');

        $fresh = TenantUser::query()->find($tenantUser->id);
        $this->assertTrue($fresh->is_investor);
        $this->assertSame($user->id, $fresh->investor_granted_by);
        $this->assertNotNull($fresh->investor_granted_at);

        // Revoking clears the grant AND the audit trail — mirrors
        // UserController::update()'s defensive can_record_payments
        // clear-on-role-change, applied here to is_investor's own toggle.
        $revokeResponse = $this->patch("/users/{$tenantUser->id}", ['is_investor' => false]);
        $revokeResponse->assertRedirect('/users');

        $revoked = TenantUser::query()->find($tenantUser->id);
        $this->assertFalse($revoked->is_investor);
        $this->assertNull($revoked->investor_granted_by);
        $this->assertNull($revoked->investor_granted_at);
    }

    public function test_a_non_admin_cannot_grant_investor_status_to_anyone(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'manager']);
        $this->actingAs($user);

        $tenantUser = TenantUser::query()->where('user_id', $user->id)->firstOrFail();

        $this->patch("/users/{$tenantUser->id}", ['is_investor' => true])->assertForbidden();

        $this->assertFalse(TenantUser::query()->find($tenantUser->id)->is_investor);
    }

    /**
     * Unlike can_record_payments (worker-only per PaymentPolicy::create()'s
     * doc comment), is_investor is a pure additive OR with no role
     * restriction implied by §7's design — confirms UpdateTenantUserRequest
     * accepts it on a non-worker row (e.g. manager) without the 422 that
     * can_record_payments would trigger there.
     */
    public function test_is_investor_can_be_granted_on_any_role_not_just_worker(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'super']);
        $this->actingAs($user);

        $tenantUser = TenantUser::query()->where('user_id', $user->id)->firstOrFail();

        // role and is_investor are set together in ONE request (rather than
        // pre-flipping the row's role via Eloquent first) so the acting
        // super user's own authorize() check — evaluated against this same
        // row's role as it stands at the START of this request — still
        // passes; this tenant fixture has only the one seeded owner row
        // (see InteractsWithTenantRoles's doc comment), so pre-flipping
        // their own role to 'manager' before the request would make them
        // unable to authorize the request at all.
        $response = $this->patch("/users/{$tenantUser->id}", ['role' => 'manager', 'is_investor' => true]);

        $response->assertSessionDoesntHaveErrors();

        $fresh = TenantUser::query()->find($tenantUser->id);
        $this->assertSame('manager', $fresh->role);
        $this->assertTrue($fresh->is_investor);
    }
}
