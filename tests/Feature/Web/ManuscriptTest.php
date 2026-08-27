<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Web (session-auth, Inertia) counterpart to tests/Feature/Api/ManuscriptTest.php
 * and ManuscriptExportTest.php, exercising App\Http\Controllers\ManuscriptController
 * instead of the API controller. Reuses the real seeded owner
 * (kelvin@shalomtech.dev), flipping their tenant_users role per test, same
 * pattern as DashboardTest.
 */
class ManuscriptTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    public function test_index_renders_with_summary_and_paginated_data(): void
    {
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manuscripts/Index')
                ->where('period', $period)
                ->has('summary.total_customers')
                ->has('manuscripts.data')
                ->has('manuscripts.meta')
                ->has('zones'));
    }

    public function test_manager_can_export_the_manuscript_register_as_a_pdf(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts/export?period='.$period);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_agent_cannot_export_the_manuscript_register(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $this->actingAsRole('agent');

        $response = $this->get('/manuscripts/export?period='.$period);

        $response->assertStatus(403);
    }

    /**
     * Stage 3 (task-scheduler.md's 2026-08-27 "manual/scheduled convergence"
     * addendum): the manual trigger no longer auto-publishes — it now lands
     * at 'pending_review' behind the same gate the scheduled path already
     * used, redirects to the new Manuscripts/RunReview.tsx screen
     * (manuscripts.runs.show), and only actually writes to `manuscripts`
     * once that run is explicitly published. This test exercises the full
     * round trip through the real HTTP entry points an admin now uses:
     * POST /manuscripts/calculate (lands pending_review, no manuscripts row
     * yet) -> POST /settings/command-runs/{run}/publish (commits it).
     */
    public function test_admin_can_run_the_manuscript_calculation(): void
    {
        // manuscript:calculate (invoked synchronously by
        // App\Http\Controllers\ManuscriptController::calculate()) owns its
        // own tenancy()->initialize()/end() lifecycle end-to-end, exactly as
        // it does in production. Stancl's tenancy()->end() purges (disconnects)
        // the tenant DB connection, which would silently roll back this
        // test's own fixtures if they were sitting in the still-open outer
        // transaction opened by initializeTenant() in setUp(). So — unlike
        // every other test in this class — this one releases that empty
        // outer transaction up front and cleans up its own rows explicitly
        // afterwards instead of relying on DatabaseTransactions-style
        // rollback. See tests/Feature/ManuscriptCalculateTest.php's
        // "test_the_command_upserts_..." test for the same workaround
        // against the raw command.
        DB::connection('tenant')->rollBack();
        tenancy()->end();

        tenancy()->initialize(Tenant::find('swecom'));
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $originalRole = TenantUser::query()->where('user_id', $user->id)->value('role');
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'admin']);
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        tenancy()->end();

        // A fixed, distant-past period rather than "now" — the command
        // processes every real customer in the tenant schema (small dataset,
        // ~550 rows), so picking a period nobody else's fixtures touch keeps
        // this test from colliding with anything meaningful, exactly like
        // tests/Feature/ManuscriptCalculateTest.php's equivalent command test.
        $period = '2020-01';

        try {
            $response = $this->actingAs($user)->post('/manuscripts/calculate', ['period' => $period]);

            $response->assertRedirect();
            $response->assertSessionHas('success');

            tenancy()->initialize(Tenant::find('swecom'));
            $run = CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->firstOrFail();
            $this->assertSame('pending_review', $run->status, 'the manual trigger must no longer auto-publish.');
            $this->assertDatabaseMissing('manuscripts', ['customer_id' => $customer->id, 'period' => $period]);
            tenancy()->end();

            $publishResponse = $this->actingAs($user)->post("/settings/command-runs/{$run->uuid}/publish");
            $publishResponse->assertRedirect();
            $publishResponse->assertSessionHas('success');

            tenancy()->initialize(Tenant::find('swecom'));
            $this->assertDatabaseHas('manuscripts', ['customer_id' => $customer->id, 'period' => $period]);
            $this->assertSame('published', $run->fresh()->status);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            Manuscript::query()->where('period', $period)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Customer::query()->whereKey($customer->id)->delete();
            Zone::query()->whereKey($zone->id)->delete();
            TenantUser::query()->where('user_id', $user->id)->update(['role' => $originalRole]);

            // Leave tenancy initialized with an empty open transaction rather
            // than ending it here: InteractsWithTenantRoles registers a
            // beforeApplicationDestroyed callback (see its setUp()) that
            // unconditionally calls DB::connection('tenant')->transactionLevel()
            // during teardown — which throws once the connection is purged
            // by tenancy()->end(). All real cleanup already happened above via
            // explicit deletes, so this transaction is just a harmless no-op
            // for that callback to roll back.
            DB::connection('tenant')->beginTransaction();
        }
    }

    /**
     * The "already safely runnable" guard (task-scheduler.md's 2026-08-27
     * addendum) at the web entry point: once a period has been calculated
     * and published, POSTing /manuscripts/calculate for that SAME period
     * again with no `confirmed_rerun` must be refused — distinct from the
     * pre-existing in-flight lock, which has nothing left to collide with
     * once the first run has finished. Same tenancy-lifecycle workaround as
     * test_admin_can_run_the_manuscript_calculation above (the command owns
     * its own tenancy()->initialize()/end()).
     *
     * Stage 3 (autoPublish:false flip): the guard only fires against a
     * PUBLISHED prior run, and the manual trigger no longer publishes on its
     * own — so this test now explicitly publishes the first run (via the
     * same endpoint an admin would actually click) before attempting the
     * unconfirmed rerun, to reach the exact state the guard is meant to
     * catch.
     */
    public function test_admin_rerunning_an_already_published_period_is_rejected_without_confirmed_rerun(): void
    {
        DB::connection('tenant')->rollBack();
        tenancy()->end();

        tenancy()->initialize(Tenant::find('swecom'));
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $originalRole = TenantUser::query()->where('user_id', $user->id)->value('role');
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'admin']);
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        tenancy()->end();

        $period = '2020-02';

        try {
            $firstResponse = $this->actingAs($user)->post('/manuscripts/calculate', ['period' => $period]);
            $firstResponse->assertRedirect();
            $firstResponse->assertSessionHas('success');

            tenancy()->initialize(Tenant::find('swecom'));
            $firstRun = CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->firstOrFail();
            $this->assertSame('pending_review', $firstRun->status);
            tenancy()->end();

            $this->actingAs($user)->post("/settings/command-runs/{$firstRun->uuid}/publish")->assertSessionHas('success');

            tenancy()->initialize(Tenant::find('swecom'));
            $this->assertSame('published', $firstRun->fresh()->status);
            tenancy()->end();

            $secondResponse = $this->actingAs($user)->post('/manuscripts/calculate', ['period' => $period]);
            $secondResponse->assertRedirect();
            $secondResponse->assertSessionHasErrors('period');

            tenancy()->initialize(Tenant::find('swecom'));
            $this->assertSame(
                1,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'a refused rerun must not create a second command_runs row.'
            );
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            Manuscript::query()->where('period', $period)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Customer::query()->whereKey($customer->id)->delete();
            Zone::query()->whereKey($zone->id)->delete();
            TenantUser::query()->where('user_id', $user->id)->update(['role' => $originalRole]);

            DB::connection('tenant')->beginTransaction();
        }
    }

    /**
     * The override escape hatch: `confirmed_rerun: true` lets an admin
     * deliberately rerun an already-published period through the web UI.
     *
     * Stage 3 (autoPublish:false flip): as above, the first run must
     * actually be published (not just dispatched) before the guard has
     * anything to fire against, so this test publishes it via the real
     * endpoint before attempting the confirmed rerun.
     */
    public function test_admin_can_rerun_an_already_published_period_with_confirmed_rerun_true(): void
    {
        DB::connection('tenant')->rollBack();
        tenancy()->end();

        tenancy()->initialize(Tenant::find('swecom'));
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $originalRole = TenantUser::query()->where('user_id', $user->id)->value('role');
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'admin']);
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        tenancy()->end();

        $period = '2020-03';

        try {
            $this->actingAs($user)->post('/manuscripts/calculate', ['period' => $period])->assertSessionHas('success');

            tenancy()->initialize(Tenant::find('swecom'));
            $firstRun = CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->firstOrFail();
            tenancy()->end();

            $this->actingAs($user)->post("/settings/command-runs/{$firstRun->uuid}/publish")->assertSessionHas('success');

            $response = $this->actingAs($user)->post('/manuscripts/calculate', [
                'period' => $period,
                'confirmed_rerun' => true,
            ]);
            $response->assertRedirect();
            $response->assertSessionHas('success');
            $response->assertSessionDoesntHaveErrors();

            tenancy()->initialize(Tenant::find('swecom'));
            $this->assertSame(
                2,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'confirmed_rerun:true must let a genuinely new run through.'
            );
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            Manuscript::query()->where('period', $period)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Customer::query()->whereKey($customer->id)->delete();
            Zone::query()->whereKey($zone->id)->delete();
            TenantUser::query()->where('user_id', $user->id)->update(['role' => $originalRole]);

            DB::connection('tenant')->beginTransaction();
        }
    }

    /**
     * `confirmed_rerun` must be validated as a real boolean, not accepted as
     * loose truthiness — a plain non-boolean string is rejected outright
     * (never silently coerced to true), since this flag consciously bypasses
     * a safety check.
     */
    public function test_confirmed_rerun_must_be_a_real_boolean(): void
    {
        $this->actingAsRole('admin');

        $period = Carbon::now()->format('Y-m');

        $response = $this->post('/manuscripts/calculate', [
            'period' => $period,
            'confirmed_rerun' => 'yes-please',
        ]);

        $response->assertSessionHasErrors('confirmed_rerun');
    }

    /**
     * Regression test for the bug found in production: an unvalidated
     * `period` reaching ManuscriptController::calculate() was passed
     * straight to ManuscriptGenerationBatchService::dispatch() with
     * autoPublish=true and no $customerIds — which recalculates and
     * publishes EVERY customer in the tenant for that literal period
     * string. A stray future period ("2031-02") fabricated real,
     * non-frozen manuscript rows for the whole tenant, which then
     * surfaced as each customer's "current bill" in
     * BillNotificationService (it reads the latest manuscript by period).
     * The fix rejects malformed/future periods before dispatch() ever runs.
     */
    public function test_a_future_period_is_rejected_and_never_dispatched(): void
    {
        $this->actingAsRole('admin');

        $futurePeriod = Carbon::now()->addYears(5)->format('Y-m');

        $response = $this->post('/manuscripts/calculate', ['period' => $futurePeriod]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('command_runs', ['command' => 'manuscript:calculate', 'period' => $futurePeriod]);
        $this->assertDatabaseMissing('manuscripts', ['period' => $futurePeriod]);
    }

    public function test_a_malformed_period_is_rejected_and_never_dispatched(): void
    {
        $this->actingAsRole('admin');

        $response = $this->post('/manuscripts/calculate', ['period' => 'not-a-period']);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('command_runs', ['command' => 'manuscript:calculate', 'period' => 'not-a-period']);
    }

    public function test_manager_cannot_run_the_manuscript_calculation(): void
    {
        $period = Carbon::now()->format('Y-m');

        $this->actingAsRole('manager');

        $response = $this->post('/manuscripts/calculate', ['period' => $period]);

        $response->assertStatus(403);
    }

    /**
     * ManuscriptRepository::aggregates() scopes total_bill/total_arrears to
     * `customers.status = 'active'` (2026-08 owner decision) — a
     * disconnected/passive/suspended customer's manuscript row still exists
     * (ManuscriptCalculator freezes their billing rather than skipping the
     * row entirely) but its total_bill/total_arrears must NOT be counted
     * into the summary, because that balance is frozen/dormant, not
     * currently-collectible money. These three tests cover each frozen
     * status; total_credit/total_collected/collection_rate are scoped the
     * same way and exercised together with total_arrears below since they
     * share the same query/reasoning.
     */
    public function test_disconnected_customers_manuscript_is_excluded_from_the_summary_totals(): void
    {
        $period = Carbon::now()->format('Y-m');
        // Filtered to a dedicated, freshly-created zone (via zone_uuid) so
        // this test's totals aren't polluted by the tenant's real seeded
        // customers/manuscripts, which also exist for the current period.
        $zone = ZoneFactory::new()->create();

        $active = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        // Explicit total_bill/total_arrears rather than withArrears() —
        // withArrears() derives total_bill from the factory's own random
        // default `bill`, not an explicit override passed to create(),
        // which would make this assertion flaky.
        ManuscriptFactory::new()->forPeriod($period)->create([
            'customer_id' => $active->id,
            'bill' => 2500,
            'total_arrears' => 500,
            'credit' => 0,
            'total_bill' => 3000,
        ]);

        $disconnected = CustomerFactory::new()->disconnected()->create(['zone_id' => $zone->id, 'bill' => 3000]);
        // Frozen customers still get a manuscript row (total_bill forced to
        // 0 by ManuscriptCalculator) carrying forward a real but dormant
        // arrears balance — this is exactly the figure that must NOT leak
        // into the summary's total_arrears.
        ManuscriptFactory::new()->forPeriod($period)->create([
            'customer_id' => $disconnected->id,
            'bill' => 3000,
            'total_arrears' => 9000,
            'credit' => 0,
            'total_bill' => 0,
        ]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts?period='.$period.'&zone_uuid='.$zone->uuid);

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_bill', '3000.00')
                ->where('summary.total_arrears', '500.00'));
    }

    public function test_suspended_customers_manuscript_is_excluded_from_the_summary_totals(): void
    {
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create();

        $active = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $active->id, 'bill' => 2500, 'total_bill' => 2500]);

        $suspended = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended', 'bill' => 3000]);
        ManuscriptFactory::new()->forPeriod($period)->create([
            'customer_id' => $suspended->id,
            'bill' => 3000,
            'total_arrears' => 6000,
            'credit' => 0,
            'total_bill' => 0,
        ]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts?period='.$period.'&zone_uuid='.$zone->uuid);

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_bill', '2500.00')
                ->where('summary.total_arrears', '0.00'));
    }

    public function test_passive_customers_manuscript_is_excluded_from_the_summary_totals(): void
    {
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create();

        $active = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $active->id, 'bill' => 2500, 'total_bill' => 2500]);

        $passive = CustomerFactory::new()->passive()->create(['zone_id' => $zone->id, 'bill' => 2000]);
        ManuscriptFactory::new()->forPeriod($period)->create([
            'customer_id' => $passive->id,
            'bill' => 2000,
            'total_arrears' => 4000,
            'credit' => 0,
            'total_bill' => 0,
        ]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts?period='.$period.'&zone_uuid='.$zone->uuid);

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_bill', '2500.00')
                ->where('summary.total_arrears', '0.00'));
    }

    public function test_active_customers_manuscript_is_included_in_the_summary_totals(): void
    {
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create();

        $active = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        ManuscriptFactory::new()->forPeriod($period)->create([
            'customer_id' => $active->id,
            'bill' => 2500,
            'total_arrears' => 1000,
            'credit' => 0,
            'total_bill' => 3500,
        ]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts?period='.$period.'&zone_uuid='.$zone->uuid);

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_customers', 1)
                ->where('summary.total_bill', '3500.00')
                ->where('summary.total_arrears', '1000.00'));
    }

    /**
     * The same customer/manuscript pair moving between an active period and
     * a frozen (disconnected) period must move in and out of the summary
     * totals accordingly — this is the scenario the freeze-then-reconnect
     * business flow actually produces (business-rules.md #6/#7).
     */
    public function test_summary_totals_change_correctly_when_a_customers_status_changes_between_periods(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);

        $activePeriod = '2024-01';
        ManuscriptFactory::new()->forPeriod($activePeriod)->create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => 500,
            'credit' => 0,
            'total_bill' => 3000,
        ]);

        $this->actingAsRole('manager');

        $activeResponse = $this->get('/manuscripts?period='.$activePeriod.'&zone_uuid='.$zone->uuid);
        $activeResponse->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_bill', '3000.00')
                ->where('summary.total_arrears', '500.00'));

        // Customer is disconnected, and the next period's manuscript run
        // freezes their billing (total_bill forced to 0, arrears carried
        // forward) — exactly ManuscriptCalculator's documented behavior.
        $customer->update(['status' => 'disconnected']);

        $frozenPeriod = '2024-02';
        ManuscriptFactory::new()->forPeriod($frozenPeriod)->create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => 3000,
            'credit' => 0,
            'total_bill' => 0,
        ]);

        $frozenResponse = $this->get('/manuscripts?period='.$frozenPeriod.'&zone_uuid='.$zone->uuid);
        $frozenResponse->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_bill', '0.00')
                ->where('summary.total_arrears', '0.00'));
    }
}
