<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CommandRun;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Settings — Company Info, Users & Roles, Command Runs (web-admin-spec.md
 * sections 3.13/3.14). Same session-auth Inertia pattern as ZoneTest/
 * AgentTest: reuse the real seeded owner (kelvin@shalomtech.dev), flipping
 * their tenant_users role per test.
 */
class SettingsTest extends TestCase
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

    // -----------------------------------------------------------------
    // Company Info
    // -----------------------------------------------------------------

    public function test_company_edit_page_renders_the_single_seeded_row(): void
    {
        $this->actingAsRole('admin');

        $response = $this->get('/settings/company');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Company')
                ->has('company')
                ->where('company.name', Company::query()->first()->name));
    }

    public function test_admin_can_update_company_info(): void
    {
        $this->actingAsRole('admin');

        $response = $this->patch('/settings/company', [
            'name' => 'SWECOM PLC',
            'location' => 'UPDATED LOCATION',
            'head_office' => 'Behind City Hall, Kumba 3, South West Region, Cameroon',
            'email' => 'shalomtech@gmail.com',
            'phone' => '676876509/672528022',
            'tech_number' => '676876509',
            'momo_number' => '676876509/672528022',
            'momo_name' => 'MUNGWAN HANS/KELVIN MEKUME',
            'reconnection_fine' => '2000',
            'arrears_second_approval_threshold' => '20000',
            'rccm_number' => 'RC/DLA/2019/PM/127651',
            'niu' => 'M012345678901A',
        ]);

        $response->assertRedirect(route('settings.company.edit'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('companies', [
            'location' => 'UPDATED LOCATION',
            'head_office' => 'Behind City Hall, Kumba 3, South West Region, Cameroon',
            'rccm_number' => 'RC/DLA/2019/PM/127651',
            'niu' => 'M012345678901A',
        ]);
    }

    public function test_admin_can_upload_a_company_logo(): void
    {
        Storage::fake('public');

        $this->actingAsRole('admin');

        $logo = UploadedFile::fake()->image('logo.png', 200, 200);

        $response = $this->patch('/settings/company', [
            'name' => 'SWECOM PLC',
            'location' => '3/CORNERS',
            'phone' => '676876509/672528022',
            'reconnection_fine' => '2000',
            'arrears_second_approval_threshold' => '20000',
            'logo' => $logo,
        ]);

        $response->assertRedirect(route('settings.company.edit'));
        $response->assertSessionHas('success');

        $company = Company::query()->first();
        $media = $company->getFirstMedia('logo');

        $this->assertNotNull($media, 'The uploaded logo should be persisted to the Company\'s logo media collection.');
        Storage::disk('public')->assertExists($media->id.'/'.$media->file_name);
        $this->assertNotNull($company->getFirstMediaUrl('logo'));

        // Retrievable from the edit page too, not just the media table directly.
        $editResponse = $this->get('/settings/company');
        $editResponse->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Company')
            ->where('company.logo_url', $company->getFirstMediaUrl('logo')));
    }

    public function test_uploading_a_new_logo_replaces_the_previous_one(): void
    {
        Storage::fake('public');

        $this->actingAsRole('admin');

        $company = Company::query()->first();
        $company->addMedia(UploadedFile::fake()->image('old-logo.png'))->toMediaCollection('logo');
        $this->assertCount(1, $company->fresh()->getMedia('logo'));

        $response = $this->patch('/settings/company', [
            'name' => 'SWECOM PLC',
            'location' => '3/CORNERS',
            'phone' => '676876509/672528022',
            'reconnection_fine' => '2000',
            'arrears_second_approval_threshold' => '20000',
            'logo' => UploadedFile::fake()->image('new-logo.png'),
        ]);

        $response->assertRedirect(route('settings.company.edit'));

        // singleFile() collection: still exactly one media row, now the new upload.
        $this->assertCount(1, $company->fresh()->getMedia('logo'));
    }

    public function test_manager_cannot_update_company_info(): void
    {
        $this->actingAsRole('manager');

        $response = $this->patch('/settings/company', [
            'name' => 'SWECOM PLC',
            'location' => 'SHOULD NOT APPLY',
            'phone' => '676876509',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('companies', ['location' => 'SHOULD NOT APPLY']);
    }

    public function test_agent_cannot_update_company_info(): void
    {
        $this->actingAsRole('agent');

        $response = $this->patch('/settings/company', [
            'name' => 'SWECOM PLC',
            'location' => 'SHOULD NOT APPLY',
            'phone' => '676876509',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('companies', ['location' => 'SHOULD NOT APPLY']);
    }

    public function test_worker_cannot_update_company_info(): void
    {
        $this->actingAsRole('worker');

        $response = $this->patch('/settings/company', [
            'name' => 'SWECOM PLC',
            'location' => 'SHOULD NOT APPLY',
            'phone' => '676876509',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('companies', ['location' => 'SHOULD NOT APPLY']);
    }

    public function test_agent_can_still_view_company_info(): void
    {
        $this->actingAsRole('agent');

        $this->get('/settings/company')->assertOk();
    }

    // -----------------------------------------------------------------
    // Users & Roles
    // -----------------------------------------------------------------

    public function test_users_index_renders_for_admin(): void
    {
        $this->actingAsRole('admin');

        $response = $this->get('/settings/users');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Users')
                ->has('users'));
    }

    public function test_agent_cannot_view_the_users_index(): void
    {
        $this->actingAsRole('agent');

        $this->get('/settings/users')->assertStatus(403);
    }

    public function test_manager_cannot_create_a_user(): void
    {
        $this->actingAsRole('manager');

        $response = $this->post('/settings/users', [
            'name' => 'Should Not Exist',
            'username' => 'shouldnotexist',
            'email' => 'shouldnotexist@example.test',
            'password' => 'password123',
            'role' => 'agent',
        ]);

        $response->assertStatus(403);
        // Explicit 'pgsql' connection — the central `users` table lives on
        // the pgsql connection, not the tenant connection that's the test's
        // default once tenancy is initialized (see InteractsWithTenantRoles).
        $this->assertDatabaseMissing('users', ['email' => 'shouldnotexist@example.test'], 'pgsql');
    }

    public function test_super_can_create_a_new_user_and_it_is_queryable_afterward(): void
    {
        // SettingsUserController::store() commits a real central `users` row
        // (pgsql connection) and a real `tenant_users` row (tenant
        // connection) — two separate DB sessions, linked by a cross-schema
        // FK. Both rows must be genuinely COMMITTED (not just sitting in an
        // open transaction on one connection) for the second insert's FK
        // check to see the first. DatabaseTransactions/InteractsWithTenantRoles
        // normally keep both connections inside an open, never-committed
        // transaction for the whole test (rolled back at teardown) — exactly
        // the cross-connection-visibility gap documented on
        // InteractsWithTenantRoles and exercised the same way by
        // ManuscriptTest::test_admin_can_run_the_manuscript_calculation.
        // Release both outer transactions up front, do the real work, then
        // clean up explicitly instead of relying on rollback.
        DB::connection('tenant')->rollBack();
        tenancy()->end();
        DB::connection('pgsql')->rollBack();

        tenancy()->initialize(Tenant::find('swecom'));
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $originalRole = TenantUser::query()->where('user_id', $user->id)->value('role');
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'super']);
        tenancy()->end();

        $createdUserId = null;

        try {
            $response = $this->actingAs($user)->post('/settings/users', [
                'name' => 'New Test User',
                'username' => 'newtestuser',
                'email' => 'newtestuser@example.test',
                'password' => 'password123',
                'role' => 'agent',
            ]);

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('success');

            $createdUser = User::query()->where('email', 'newtestuser@example.test')->first();
            $this->assertNotNull($createdUser, 'The new central user row was not created.');
            $createdUserId = $createdUser->id;

            tenancy()->initialize(Tenant::find('swecom'));
            $this->assertDatabaseHas('tenant_users', [
                'user_id' => $createdUser->id,
                'role' => 'agent',
            ]);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            if ($createdUserId !== null) {
                TenantUser::query()->where('user_id', $createdUserId)->delete();
            }
            TenantUser::query()->where('user_id', $user->id)->update(['role' => $originalRole]);

            // Leave tenancy initialized with an empty open transaction rather
            // than ending it here: InteractsWithTenantRoles registers a
            // beforeApplicationDestroyed callback that unconditionally calls
            // DB::connection('tenant')->transactionLevel() during teardown —
            // which throws once the connection is purged by tenancy()->end().
            DB::connection('tenant')->beginTransaction();

            if ($createdUserId !== null) {
                User::query()->whereKey($createdUserId)->delete();
            }

            // Same reasoning on the pgsql side: DatabaseTransactions' own
            // teardown callback rolls back whatever transaction is open on
            // the default connection; reopen one so that call has something
            // harmless to roll back instead of operating on a bare
            // autocommit connection.
            DB::connection('pgsql')->beginTransaction();
        }
    }

    public function test_super_can_change_an_existing_users_role(): void
    {
        $user = $this->actingAsRole('super');

        $tenantUser = TenantUser::query()->where('user_id', $user->id)->firstOrFail();

        $response = $this->patch("/settings/users/{$tenantUser->id}", ['role' => 'manager']);

        $response->assertRedirect(route('settings.users.index'));
        $this->assertDatabaseHas('tenant_users', ['id' => $tenantUser->id, 'role' => 'manager']);
    }

    /**
     * The narrow per-user payment-recording grant (PaymentPolicy::create()'s
     * doc comment / UpdateTenantUserRequest's can_record_payments rule): a
     * super/admin can grant it to a worker, but setting it on any other role
     * is rejected outright rather than silently ignored. Needs a SECOND,
     * genuinely separate tenant_users row (distinct from the acting super
     * user) — same "release the outer transaction, do real committed work,
     * clean up in finally" strategy as
     * test_super_can_create_a_new_user_and_it_is_queryable_afterward above,
     * for the identical cross-connection-visibility reason documented
     * there.
     */
    public function test_can_record_payments_can_be_granted_to_a_worker_but_rejected_for_other_roles(): void
    {
        DB::connection('tenant')->rollBack();
        tenancy()->end();
        DB::connection('pgsql')->rollBack();

        tenancy()->initialize(Tenant::find('swecom'));
        $owner = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $originalRole = TenantUser::query()->where('user_id', $owner->id)->value('role');
        TenantUser::query()->where('user_id', $owner->id)->update(['role' => 'super']);
        tenancy()->end();

        $workerUserId = null;
        $managerUserId = null;

        try {
            $worker = User::query()->create([
                'name' => 'Test Worker', 'username' => 'testworkercrp', 'email' => 'testworkercrp@example.test',
                'password' => 'password123', 'status' => 'active',
            ]);
            $workerUserId = $worker->id;

            $manager = User::query()->create([
                'name' => 'Test Manager', 'username' => 'testmanagercrp', 'email' => 'testmanagercrp@example.test',
                'password' => 'password123', 'status' => 'active',
            ]);
            $managerUserId = $manager->id;

            tenancy()->initialize(Tenant::find('swecom'));
            $workerTenantUser = TenantUser::query()->create([
                'user_id' => $worker->id, 'tenant_id' => 'swecom', 'role' => 'worker',
            ]);
            $managerTenantUser = TenantUser::query()->create([
                'user_id' => $manager->id, 'tenant_id' => 'swecom', 'role' => 'manager',
            ]);
            tenancy()->end();

            $grantResponse = $this->actingAs($owner)
                ->patch("/settings/users/{$workerTenantUser->id}", ['can_record_payments' => true]);

            $grantResponse->assertRedirect(route('settings.users.index'));

            tenancy()->initialize(Tenant::find('swecom'));
            $this->assertDatabaseHas('tenant_users', [
                'id' => $workerTenantUser->id,
                'can_record_payments' => true,
            ]);
            tenancy()->end();

            $rejectResponse = $this->actingAs($owner)
                ->patch("/settings/users/{$managerTenantUser->id}", ['can_record_payments' => true]);

            $rejectResponse->assertSessionHasErrors('can_record_payments');

            tenancy()->initialize(Tenant::find('swecom'));
            $this->assertDatabaseHas('tenant_users', [
                'id' => $managerTenantUser->id,
                'can_record_payments' => false,
            ]);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            if ($workerUserId !== null) {
                TenantUser::query()->where('user_id', $workerUserId)->delete();
            }
            if ($managerUserId !== null) {
                TenantUser::query()->where('user_id', $managerUserId)->delete();
            }
            TenantUser::query()->where('user_id', $owner->id)->update(['role' => $originalRole]);

            // See test_super_can_create_a_new_user_and_it_is_queryable_afterward's
            // identical finally block for why an empty transaction is
            // reopened on both connections here rather than leaving them bare.
            DB::connection('tenant')->beginTransaction();

            if ($workerUserId !== null) {
                User::query()->whereKey($workerUserId)->delete();
            }
            if ($managerUserId !== null) {
                User::query()->whereKey($managerUserId)->delete();
            }

            DB::connection('pgsql')->beginTransaction();
        }
    }

    public function test_super_can_deactivate_a_user(): void
    {
        $user = $this->actingAsRole('super');

        $tenantUser = TenantUser::query()->where('user_id', $user->id)->firstOrFail();

        $response = $this->post("/settings/users/{$tenantUser->id}/deactivate");

        $response->assertRedirect(route('settings.users.index'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'passive'], 'pgsql');
    }

    // -----------------------------------------------------------------
    // Command Runs
    // -----------------------------------------------------------------

    public function test_command_runs_index_renders_for_admin(): void
    {
        CommandRun::query()->create([
            'command' => 'manuscript:calculate',
            'period' => '2026-07',
            'ran_at' => now(),
            'metadata' => ['rows' => 521, 'skipped_rejected' => 0],
        ]);

        $this->actingAsRole('admin');

        $response = $this->get('/settings/command-runs');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/CommandRuns')
                ->has('runs.data'));
    }

    public function test_agent_cannot_view_command_runs(): void
    {
        $this->actingAsRole('agent');

        $this->get('/settings/command-runs')->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Guests
    // -----------------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/company')->assertRedirect('/login');
        $this->get('/settings/users')->assertRedirect('/login');
        $this->get('/settings/command-runs')->assertRedirect('/login');
    }
}
