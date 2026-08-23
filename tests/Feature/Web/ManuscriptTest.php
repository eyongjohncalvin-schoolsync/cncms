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
            $this->assertDatabaseHas('manuscripts', ['customer_id' => $customer->id, 'period' => $period]);
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

    public function test_manager_cannot_run_the_manuscript_calculation(): void
    {
        $period = Carbon::now()->format('Y-m');

        $this->actingAsRole('manager');

        $response = $this->post('/manuscripts/calculate', ['period' => $period]);

        $response->assertStatus(403);
    }
}
