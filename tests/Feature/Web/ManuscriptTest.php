<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Exports\ManuscriptRegisterExport;
use App\Models\CommandRun;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\Feature\Concerns\UsesDisposableTenant;
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
    use UsesDisposableTenant;

    /**
     * The three tests that invoke the real manuscript:calculate command/
     * endpoint (see each one's own doc comment) provision their own
     * disposable tenant instead — never touching real swecom at all, not
     * even briefly. Initializing swecom here first and then trying to
     * transition to a fresh disposable tenant mid-test was tried and
     * doesn't work cleanly (Stancl's Tenant::create() migration step ends
     * up with no search_path selected on the `tenant` connection,
     * regardless of an explicit DB::purge('tenant') first) — so those three
     * tests are excluded from this shared real-tenant setup entirely,
     * exactly mirroring tests/Feature/ManuscriptCalculateTest.php's clean-
     * slate-from-the-start pattern.
     */
    private const array DISPOSABLE_TENANT_TESTS = [
        'test_admin_can_run_the_manuscript_calculation',
        'test_admin_rerunning_an_already_published_period_is_rejected_without_confirmed_rerun',
        'test_admin_can_rerun_an_already_published_period_with_confirmed_rerun_true',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array($this->name(), self::DISPOSABLE_TENANT_TESTS, true)) {
            $this->initializeTenant();
        }
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

    public function test_index_search_filters_the_row_list_to_the_matching_customer(): void
    {
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create();

        $wanted = CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => 'Zephaniah Ndip', 'phone' => '677111222']);
        $other = CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => 'Marceline Ako', 'phone' => '699333444']);

        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $wanted->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $other->id]);

        $this->actingAsRole('manager');

        $response = $this->get('/manuscripts?period='.$period.'&zone_uuid='.$zone->uuid.'&search=zephan');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manuscripts/Index')
                ->has('manuscripts.data', 1)
                ->where('manuscripts.data.0.customer_name', 'Zephaniah Ndip'));
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

    public function test_manager_can_export_the_manuscript_register_as_excel(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        // Scoped to the freshly-created zone so the workbook only renders
        // this test's own row, not the tenant's ~hundreds of real seeded
        // manuscripts for the current period.
        $response = $this->get('/manuscripts/export?format=xlsx&period='.$period.'&zone_uuid='.$zone->uuid);

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertGreaterThan(0, $response->getFile()->getSize());
    }

    public function test_agent_cannot_export_the_manuscript_register_as_excel(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $this->actingAsRole('agent');

        $this->get('/manuscripts/export?format=xlsx&period='.$period)->assertStatus(403);
    }

    /**
     * Both export formats run off the same ManuscriptService::exportData()
     * payload, so the period/zone/status filtering is shared — proven here
     * against the Excel export (its row set is directly inspectable via
     * Excel::fake(), unlike the PDF's binary stream): filtering to one zone
     * yields exactly that zone's row, not the other zone's.
     */
    public function test_the_export_respects_a_zone_filter(): void
    {
        Excel::fake();
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');

        $wantedZone = ZoneFactory::new()->create();
        $otherZone = ZoneFactory::new()->create();

        $wanted = CustomerFactory::new()->create(['zone_id' => $wantedZone->id]);
        $other = CustomerFactory::new()->create(['zone_id' => $otherZone->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $wanted->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $other->id]);

        $this->actingAsRole('manager');

        $this->get('/manuscripts/export?format=xlsx&period='.$period.'&zone_uuid='.$wantedZone->uuid)
            ->assertOk();

        Excel::assertDownloaded(
            'manuscript-'.$period.'.xlsx',
            fn (ManuscriptRegisterExport $export): bool => count($export->array()) === 1
                && $export->array()[0][1] === $wanted->name,
        );
    }

    /**
     * The register carries a blank "Paid" column (between "Total Bill" and
     * "Status") that the manager fills in by hand after collecting — see
     * ManuscriptRegisterExport's class doc and the PDF blade. It must ship as
     * a real, empty column in the workbook, not be silently dropped.
     */
    public function test_the_export_carries_a_blank_paid_column(): void
    {
        Excel::fake();
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $this->get('/manuscripts/export?format=xlsx&period='.$period.'&zone_uuid='.$zone->uuid)
            ->assertOk();

        Excel::assertDownloaded(
            'manuscript-'.$period.'.xlsx',
            function (ManuscriptRegisterExport $export): bool {
                $paidIndex = array_search('Paid', $export->headings(), true);

                return $paidIndex === 8
                    && $export->headings()[$paidIndex + 1] === 'Status'
                    && $export->array()[0][$paidIndex] === null;
            },
        );
    }

    /**
     * The register PDF defaults to A4 portrait (fits more customer rows per
     * page — the owner's call); `?orientation=landscape` opts into the wide
     * layout, and anything else is a 422. See ManuscriptController::export().
     */
    public function test_the_register_pdf_orientation_is_a_validated_optional_param(): void
    {
        CompanyFactory::new()->create();
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        // No orientation -> defaults to portrait, still a clean PDF stream.
        $this->get('/manuscripts/export?period='.$period)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Explicit landscape is honored.
        $this->get('/manuscripts/export?period='.$period.'&orientation=landscape')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Explicit portrait is accepted too.
        $this->get('/manuscripts/export?period='.$period.'&orientation=portrait')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Anything else is rejected rather than silently coerced.
        $this->get('/manuscripts/export?period='.$period.'&orientation=sideways')
            ->assertStatus(422);
    }

    /**
     * The blade switches both the dompdf `@page` size and its fixed
     * column-width set on the orientation, defaulting to portrait. Asserted
     * at the rendered-HTML level since the PDF stream itself is opaque binary.
     */
    public function test_the_register_blade_switches_page_size_and_columns_on_orientation(): void
    {
        $base = [
            'period' => '2026-08',
            'company' => null,
            'manuscripts' => collect(),
            'summary' => [
                'total_customers' => 0,
                'total_bill' => '0',
                'total_arrears' => '0',
                'total_credit' => '0',
                'total_collected' => '0',
                'collection_rate' => 0.0,
            ],
        ];

        $default = view('pdf.manuscript', $base)->render();
        $this->assertStringContainsString('size: a4 portrait', $default);
        $this->assertStringContainsString('width: 17%', $default); // portrait Name column

        $landscape = view('pdf.manuscript', [...$base, 'orientation' => 'landscape'])->render();
        $this->assertStringContainsString('size: a4 landscape', $landscape);
        $this->assertStringContainsString('width: 20%', $landscape); // landscape Name column

        // Unknown values fall back to portrait, never render a bare "a4 ".
        $bogus = view('pdf.manuscript', [...$base, 'orientation' => 'sideways'])->render();
        $this->assertStringContainsString('size: a4 portrait', $bogus);
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
     *
     * 2026-08-28: converted to a disposable tenant (see UsesDisposableTenant
     * and task-scheduler.md's 2026-08-28 addendum) — this test used to run
     * manuscript:calculate against the REAL swecom tenant (its own
     * tenancy()->initialize()/end() lifecycle purges/reconnects the `tenant`
     * PDO connection mid-test, which is exactly the mechanism that let real
     * fixtures survive as committed data if the process was ever killed
     * before its old manual cleanup ran — the same root cause as both prior
     * production data-corruption incidents this session).
     */
    public function test_admin_can_run_the_manuscript_calculation(): void
    {
        // DatabaseTransactions wraps this test's default (central `pgsql`)
        // connection in an outer, uncommitted transaction — but
        // provisionDisposableTenant()'s CREATE SCHEMA runs on that same
        // connection, and the migration step right after runs on the
        // separate `tenant` session, which cannot see an uncommitted DDL
        // change from a different Postgres session. Committing for real
        // first (mirroring provisionDisposableTenantAdmin()'s own pattern
        // below) makes the new schema actually visible cross-session.
        // tests/Feature/ManuscriptCalculateTest.php never hits this because
        // it doesn't use DatabaseTransactions at all.
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }

        $tenant = $this->provisionDisposableTenant('wmct');
        $user = $this->provisionDisposableTenantAdmin($tenant, 'admin');

        tenancy()->initialize($tenant);
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        tenancy()->end();

        $period = Carbon::now()->format('Y-m');

        try {
            $response = $this->actingAs($user)->post('/manuscripts/calculate', ['period' => $period]);

            $response->assertRedirect();
            $response->assertSessionHas('success');

            tenancy()->initialize($tenant);
            $run = CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->firstOrFail();
            $this->assertSame('pending_review', $run->status, 'the manual trigger must no longer auto-publish.');
            $this->assertDatabaseMissing('manuscripts', ['customer_id' => $customer->id, 'period' => $period], 'tenant');
            tenancy()->end();

            $publishResponse = $this->actingAs($user)->post("/settings/command-runs/{$run->uuid}/publish");
            $publishResponse->assertRedirect();
            $publishResponse->assertSessionHas('success');

            tenancy()->initialize($tenant);
            $this->assertDatabaseHas('manuscripts', ['customer_id' => $customer->id, 'period' => $period], 'tenant');
            $this->assertSame('published', $run->fresh()->status);
            tenancy()->end();
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $tenant->delete();
            User::query()->whereKey($user->id)->delete();

            // DatabaseTransactions' own teardown expects an open transaction
            // on the default connection — provisionDisposableTenantAdmin()
            // committed it for real to make the new User row visible
            // cross-session; this reopens an empty one for the framework to
            // roll back, the same no-op-cleanup pattern already used for the
            // `tenant` connection elsewhere in this file.
            if (DB::connection()->transactionLevel() === 0) {
                DB::connection()->beginTransaction();
            }
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
        // See test_admin_can_run_the_manuscript_calculation's comment on
        // this same commit — provisionDisposableTenant()'s CREATE SCHEMA
        // must be committed for real before the migration step (a separate
        // Postgres session) can see the new schema.
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }

        $tenant = $this->provisionDisposableTenant('wmct');
        $user = $this->provisionDisposableTenantAdmin($tenant, 'admin');

        tenancy()->initialize($tenant);
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        tenancy()->end();

        $period = Carbon::now()->format('Y-m');

        try {
            $firstResponse = $this->actingAs($user)->post('/manuscripts/calculate', ['period' => $period]);
            $firstResponse->assertRedirect();
            $firstResponse->assertSessionHas('success');

            tenancy()->initialize($tenant);
            $firstRun = CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->firstOrFail();
            $this->assertSame('pending_review', $firstRun->status);
            tenancy()->end();

            $this->actingAs($user)->post("/settings/command-runs/{$firstRun->uuid}/publish")->assertSessionHas('success');

            tenancy()->initialize($tenant);
            $this->assertSame('published', $firstRun->fresh()->status);
            tenancy()->end();

            $secondResponse = $this->actingAs($user)->post('/manuscripts/calculate', ['period' => $period]);
            $secondResponse->assertRedirect();
            $secondResponse->assertSessionHasErrors('period');

            tenancy()->initialize($tenant);
            $this->assertSame(
                1,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'a refused rerun must not create a second command_runs row.'
            );
            tenancy()->end();
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $tenant->delete();
            User::query()->whereKey($user->id)->delete();

            if (DB::connection()->transactionLevel() === 0) {
                DB::connection()->beginTransaction();
            }
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
        // See test_admin_can_run_the_manuscript_calculation's comment on
        // this same commit — provisionDisposableTenant()'s CREATE SCHEMA
        // must be committed for real before the migration step (a separate
        // Postgres session) can see the new schema.
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }

        $tenant = $this->provisionDisposableTenant('wmct');
        $user = $this->provisionDisposableTenantAdmin($tenant, 'admin');

        tenancy()->initialize($tenant);
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        tenancy()->end();

        $period = Carbon::now()->format('Y-m');

        try {
            $this->actingAs($user)->post('/manuscripts/calculate', ['period' => $period])->assertSessionHas('success');

            tenancy()->initialize($tenant);
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

            tenancy()->initialize($tenant);
            $this->assertSame(
                2,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'confirmed_rerun:true must let a genuinely new run through.'
            );
            tenancy()->end();
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $tenant->delete();
            User::query()->whereKey($user->id)->delete();

            if (DB::connection()->transactionLevel() === 0) {
                DB::connection()->beginTransaction();
            }
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
