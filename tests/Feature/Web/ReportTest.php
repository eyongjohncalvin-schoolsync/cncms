<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\CommandRun;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Support\BusinessTimezone;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Web (session-auth, Inertia) tests for the /reports feature — see
 * App\Services\ReportService's class doc and App\Http\Controllers\ReportController.
 * Follows tests/Feature/Web/ResourceTest.php's actingAsRole() convention and
 * tests/Feature/Api/BranchScopingTest.php's twoBranches() fixture pattern.
 */
class ReportTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        // Same rationale as BranchScopingTest::setUp() — the 'array' cache
        // store isn't guaranteed a fresh backing array per test method in
        // this runner, and ReportService's cache keys are branch/period
        // suffixed, so two test methods hitting the same tier+date+scope
        // could otherwise share a stale entry across tests.
        Cache::flush();
    }

    private function actingAsRole(string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array{0: Branch, 1: Zone, 2: Branch, 3: Zone}
     */
    private function twoBranches(): array
    {
        $branchA = BranchFactory::new()->create();
        $branchB = BranchFactory::new()->create();
        $zoneA = ZoneFactory::new()->create(['branch_id' => $branchA->id]);
        $zoneB = ZoneFactory::new()->create(['branch_id' => $branchB->id]);

        return [$branchA, $zoneA, $branchB, $zoneB];
    }

    /**
     * The current calendar day in WAT (App\Support\BusinessTimezone::WAT) —
     * matching exactly what App\Services\ReportService::daily() uses to
     * build its cache key, NOT plain now()->format('Y-m-d') (UTC per
     * config('app.timezone')). The two genuinely diverge for one hour a
     * day (23:00-23:59 UTC, when WAT has already rolled over to the next
     * calendar date) — using the wrong one here would make any
     * Cache::forget() call in these tests silently target the wrong key
     * during that window.
     */
    private function todayWat(): string
    {
        return Carbon::now(BusinessTimezone::WAT)->format('Y-m-d');
    }

    /**
     * Same rationale as todayWat(), for the monthly tier's period —
     * matters only on the last calendar day of a month near the UTC/WAT
     * divergence window, but cheap to get right consistently.
     */
    private function thisMonthWat(): string
    {
        return Carbon::now(BusinessTimezone::WAT)->format('Y-m');
    }

    // -----------------------------------------------------------------
    // Role-based access
    // -----------------------------------------------------------------

    public function test_super_can_view_the_default_monthly_report(): void
    {
        $this->actingAsRole('super');

        $response = $this->get('/reports');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('tier', 'monthly')
            ->has('report.collection_health'));
    }

    public function test_manager_defaults_to_the_weekly_tier(): void
    {
        $this->actingAsRole('manager');

        $response = $this->get('/reports');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('tier', 'weekly'));
    }

    public function test_agent_defaults_to_the_daily_tier(): void
    {
        $this->actingAsRole('agent');

        $response = $this->get('/reports');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('tier', 'daily'));
    }

    public function test_admin_can_view_all_three_tiers(): void
    {
        $this->actingAsRole('admin');

        foreach (['daily', 'weekly', 'monthly'] as $tier) {
            $response = $this->get("/reports?tier={$tier}");

            $response->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('tier', $tier));
        }
    }

    public function test_a_worker_is_forbidden_from_viewing_reports(): void
    {
        $this->actingAsRole('worker');

        $response = $this->get('/reports');

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Branch fencing (security boundary)
    // -----------------------------------------------------------------

    public function test_branch_fenced_manager_never_sees_another_branchs_daily_collections(): void
    {
        [$branchA, $zoneA, , $zoneB] = $this->twoBranches();

        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id]);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id]);

        PaymentFactory::new()->create(['customer_id' => $customerA->id, 'amount' => 3000, 'verification_status' => 'verified']);
        PaymentFactory::new()->create(['customer_id' => $customerB->id, 'amount' => 9000, 'verification_status' => 'verified']);

        $this->actingAsRole('manager', $branchA->id);

        $response = $this->get('/reports?tier=daily');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.payments.verified', '3000.00'));
    }

    public function test_null_branch_manager_sees_collections_from_both_branches(): void
    {
        [, $zoneA, , $zoneB] = $this->twoBranches();

        // branch_id left null — unrestricted, sees every branch. Asserted
        // as a before/after delta rather than an absolute total: the real
        // swecom tenant this test runs against (see
        // Tests\Feature\Api\Concerns\InteractsWithTenantRoles) already
        // carries historic payments, some of which may legitimately have
        // been recorded "today" — same reasoning as
        // BranchScopingTest::test_null_branch_manager_sees_customers_from_both_branches()
        // using `search` instead of a raw count.
        $this->actingAsRole('manager');

        $before = null;
        $this->get('/reports?tier=daily')->assertInertia(function (Assert $page) use (&$before) {
            $before = $page->toArray()['props']['report']['payments']['verified'];
        });

        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id]);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id]);

        PaymentFactory::new()->create(['customer_id' => $customerA->id, 'amount' => 3000, 'verification_status' => 'verified']);
        PaymentFactory::new()->create(['customer_id' => $customerB->id, 'amount' => 9000, 'verification_status' => 'verified']);

        Cache::forget('reports:daily:'.$this->todayWat().':all');

        $after = null;
        $this->get('/reports?tier=daily')->assertOk()->assertInertia(function (Assert $page) use (&$after) {
            $after = $page->toArray()['props']['report']['payments']['verified'];
        });

        $this->assertSame('12000.00', bcsub($after, $before, 2), 'Both branches\' verified payments must be counted for an unrestricted manager.');
    }

    public function test_agent_is_fenced_to_their_own_zone_not_just_their_branch(): void
    {
        [, $zoneA, , $zoneB] = $this->twoBranches();

        // A second zone in the SAME branch as zoneA — proves the agent fence
        // is the zone itself, not merely the branch (which would also let
        // this second zone's payment through).
        $branchA = Zone::query()->find($zoneA->id)->branch;
        $zoneASibling = ZoneFactory::new()->create(['branch_id' => $branchA->id]);

        $customerOwnZone = CustomerFactory::new()->create(['zone_id' => $zoneA->id]);
        $customerSiblingZone = CustomerFactory::new()->create(['zone_id' => $zoneASibling->id]);
        $customerOtherBranch = CustomerFactory::new()->create(['zone_id' => $zoneB->id]);

        PaymentFactory::new()->create(['customer_id' => $customerOwnZone->id, 'amount' => 2500, 'verification_status' => 'verified']);
        PaymentFactory::new()->create(['customer_id' => $customerSiblingZone->id, 'amount' => 4000, 'verification_status' => 'verified']);
        PaymentFactory::new()->create(['customer_id' => $customerOtherBranch->id, 'amount' => 9000, 'verification_status' => 'verified']);

        $user = $this->actingAsRole('agent'); // tenant_users.branch_id left null on purpose
        AgentFactory::new()->create(['zone_id' => $zoneA->id, 'user_id' => $user->id]);

        $response = $this->get('/reports?tier=daily');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.payments.verified', '2500.00'));
    }

    public function test_weekly_league_table_is_scoped_to_the_managers_own_branch(): void
    {
        [$branchA, $zoneA, , $zoneB] = $this->twoBranches();

        CustomerFactory::new()->active()->create(['zone_id' => $zoneA->id, 'bill' => 2500]);
        CustomerFactory::new()->active()->create(['zone_id' => $zoneB->id, 'bill' => 2500]);

        $this->actingAsRole('manager', $branchA->id);

        $response = $this->get('/reports?tier=weekly');

        $response->assertOk()->assertInertia(function (Assert $page) use ($zoneA, $zoneB) {
            $page->has('report.league_table', 1);
            $page->where('report.league_table.0.zone_uuid', $zoneA->uuid);
        });
    }

    // -----------------------------------------------------------------
    // Monthly billing-run empty state
    // -----------------------------------------------------------------

    public function test_monthly_report_shows_empty_state_when_no_billing_run_exists(): void
    {
        // A far-future period, guaranteed to have no manuscript:calculate
        // run recorded against it — the real swecom tenant this test runs
        // against already carries 14 historic command_runs rows (see
        // SKILL.md), so the *current* calendar month cannot be relied on to
        // be run-less the way it could be in an empty test database.
        $period = '2099-01';

        $this->actingAsRole('super');

        $response = $this->get("/reports?tier=monthly&date={$period}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.billing_ledger', null));
    }

    public function test_monthly_report_surfaces_the_billing_run_when_one_exists(): void
    {
        $period = $this->thisMonthWat();

        CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => $period,
            'ran_at' => now(),
            'metadata' => [
                'customers_processed' => 42,
                'frozen_customers' => 3,
                'total_bill_sum' => 105000.0,
                'total_arrears_sum' => 20000.0,
                'total_credit_sum' => 5000.0,
                'errors' => 0,
                'error_details' => [],
                'duration_ms' => 1234.5,
            ],
        ]);

        $this->actingAsRole('admin');

        $response = $this->get("/reports?tier=monthly&date={$period}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.billing_ledger.customers_processed', 42)
            ->where('report.billing_ledger.errors', 0));
    }

    // Deliberately two separate test methods rather than one method
    // switching role mid-test: Laravel\Routing\Route::getController() caches
    // the resolved controller instance on the Route object after its first
    // resolution, so a second $this->get() to the SAME route+URI within one
    // test method reuses the first request's already-constructed
    // ReportController (and therefore its constructor-injected
    // TenantContext) even after actingAsRole() rebinds a fresh TenantContext
    // into the container — a test-process-only artifact (a real deployment
    // boots a fresh Route/Router per request) that this codebase's other
    // role-switch-mid-test assertions avoid by checking the role via a
    // freshly-per-call-resolved Policy (Gate never caches policy instances)
    // rather than a constructor-injected dependency. Splitting into two
    // methods sidesteps it entirely — each method gets a completely fresh
    // application/router.
    public function test_monthly_pnl_block_is_present_for_super(): void
    {
        $period = $this->thisMonthWat();

        $this->actingAsRole('super');

        $this->get("/reports?tier=monthly&date={$period}")
            ->assertInertia(fn (Assert $page) => $page->has('report.pnl'));
    }

    public function test_monthly_pnl_block_is_absent_for_manager(): void
    {
        $period = $this->thisMonthWat();

        $this->actingAsRole('manager');

        $this->get("/reports?tier=monthly&date={$period}")
            ->assertInertia(fn (Assert $page) => $page->missing('report.pnl'));
    }

    // -----------------------------------------------------------------
    // Tiered cache behavior
    // -----------------------------------------------------------------

    public function test_daily_report_is_cached_under_the_documented_key_shape(): void
    {
        $today = $this->todayWat();

        $this->actingAsRole('super');

        $this->assertFalse(Cache::has("reports:daily:{$today}:all"));

        $this->get('/reports?tier=daily')->assertOk();

        $this->assertTrue(Cache::has("reports:daily:{$today}:all"), 'The daily report must be cached under reports:daily:{date}:{branch|all}.');
    }

    public function test_a_cached_daily_report_does_not_reflect_a_new_payment_until_forgotten(): void
    {
        // A fresh branch/zone — not the unrestricted 'all' scope — so the
        // "zero payments" baseline is actually trustworthy against the real
        // swecom tenant's pre-existing historic data (see the branch-fencing
        // tests above for the same reasoning).
        $branch = BranchFactory::new()->create();
        $zone = ZoneFactory::new()->create(['branch_id' => $branch->id]);
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        $today = $this->todayWat();

        $this->actingAsRole('manager', $branch->id);

        // Prime the cache with zero payments recorded.
        $this->get('/reports?tier=daily')->assertInertia(fn (Assert $page) => $page
            ->where('report.payments.count', 0));

        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 5000, 'verification_status' => 'verified']);

        // Still cached — the new payment must not appear yet.
        $this->get('/reports?tier=daily')->assertInertia(fn (Assert $page) => $page
            ->where('report.payments.count', 0));

        Cache::forget("reports:daily:{$today}:{$branch->id}");

        // Cache cleared — now it appears.
        $this->get('/reports?tier=daily')->assertInertia(fn (Assert $page) => $page
            ->where('report.payments.count', 1));
    }

    public function test_forgetting_report_cache_on_payment_verification_write(): void
    {
        $branch = BranchFactory::new()->create();
        $zone = ZoneFactory::new()->create(['branch_id' => $branch->id]);
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id, 'amount' => 2500]);

        $this->actingAsRole('manager', $branch->id);

        // Prime the daily report cache (nothing approved yet today).
        $this->get('/reports?tier=daily')->assertInertia(fn (Assert $page) => $page
            ->where('report.verifications_actioned.approved', 0));

        $this->post("/payments/{$payment->uuid}/verify", ['action' => 'approve'])->assertRedirect();

        // PaymentVerificationService::verify() must have forgotten the
        // report cache key so this reflects the approval immediately,
        // without needing to wait out the TTL.
        $this->get('/reports?tier=daily')->assertInertia(fn (Assert $page) => $page
            ->where('report.verifications_actioned.approved', 1));
    }

    // -----------------------------------------------------------------
    // Monthly PDF export
    // -----------------------------------------------------------------

    public function test_manager_can_export_the_monthly_report_as_a_pdf(): void
    {
        $period = $this->thisMonthWat();
        $customer = CustomerFactory::new()->create();
        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500, 'verification_status' => 'verified']);

        $this->actingAsRole('manager');

        $response = $this->get("/reports/export?date={$period}");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_agent_cannot_export_the_monthly_report(): void
    {
        $this->actingAsRole('agent');

        $response = $this->get('/reports/export?date='.$this->thisMonthWat());

        $response->assertStatus(403);
    }
}
