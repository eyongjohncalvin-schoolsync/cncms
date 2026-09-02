<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CommandRun;
use App\Models\Company;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
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
    // Users & Roles moved to the Users Control Center (/users) in RBAC v2
    // Wave 3 — those assertions now live in
    // tests/Feature/Web/UsersControlCenterTest.php and RoleManagementTest.php.
    // Only the legacy-URL redirect is checked here.
    // -----------------------------------------------------------------

    public function test_legacy_settings_users_url_redirects_to_the_users_control_center(): void
    {
        $this->actingAsRole('admin');

        $this->get('/settings/users')->assertRedirect('/users');
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
        $this->get('/settings/command-runs')->assertRedirect('/login');
    }
}
