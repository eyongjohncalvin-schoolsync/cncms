<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Users Control Center — Users tab (RBAC v2 Wave 3). This is the old
 * Settings → Users & Roles screen relocated to /users; the user-management
 * assertions that used to live in SettingsTest moved here with it. Same
 * session-auth pattern as SettingsTest: reuse the real seeded owner
 * (kelvin@shalomtech.dev), flipping their tenant_users role per test.
 */
class UsersControlCenterTest extends TestCase
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
    // Users tab — access
    // -----------------------------------------------------------------

    public function test_users_index_renders_for_admin_with_roles_and_users(): void
    {
        $this->actingAsRole('admin');

        $this->get('/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('UsersControlCenter/Users')
                ->has('users')
                // The 5 seeded system roles must all be offered; an admin
                // may also have added custom roles on the real `tenantswecom`
                // schema this runs against, so don't assert an exact count.
                ->where('roles', fn ($roles) => collect($roles)->pluck('name')
                    ->intersect(['super', 'admin', 'manager', 'agent', 'worker'])
                    ->count() === 5)
                ->has('branches'));
    }

    public function test_agent_cannot_view_the_users_index(): void
    {
        $this->actingAsRole('agent');

        $this->get('/users')->assertStatus(403);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/users')->assertRedirect('/login');
        $this->get('/users/roles')->assertRedirect('/login');
    }

    public function test_legacy_settings_users_url_redirects_to_the_new_home(): void
    {
        $this->actingAsRole('admin');

        $this->get('/settings/users')->assertRedirect('/users');
    }

    // -----------------------------------------------------------------
    // Users tab — mutations gated by users.manage
    // -----------------------------------------------------------------

    public function test_manager_cannot_create_a_user(): void
    {
        $this->actingAsRole('manager');

        $this->post('/users', [
            'name' => 'Should Not Exist',
            'username' => 'shouldnotexist',
            'email' => 'shouldnotexist@example.test',
            'password' => 'password123',
            'role' => 'agent',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'shouldnotexist@example.test'], 'pgsql');
    }

    public function test_super_can_change_an_existing_users_role(): void
    {
        $user = $this->actingAsRole('super');
        $tenantUser = TenantUser::query()->where('user_id', $user->id)->firstOrFail();

        $this->patch("/users/{$tenantUser->id}", ['role' => 'manager'])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('tenant_users', ['id' => $tenantUser->id, 'role' => 'manager']);
    }

    public function test_role_rule_rejects_a_name_that_is_not_a_real_role(): void
    {
        $user = $this->actingAsRole('super');
        $tenantUser = TenantUser::query()->where('user_id', $user->id)->firstOrFail();

        $this->patch("/users/{$tenantUser->id}", ['role' => 'wizard'])
            ->assertSessionHasErrors('role');
    }

    public function test_super_can_deactivate_a_user(): void
    {
        $user = $this->actingAsRole('super');
        $tenantUser = TenantUser::query()->where('user_id', $user->id)->firstOrFail();

        $this->post("/users/{$tenantUser->id}/deactivate")
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'passive'], 'pgsql');
    }

    /**
     * End to end: a membership row carrying a CUSTOM role name resolves
     * through TenantContext to that role's permission set — the custom role
     * grants `audit.view` (so /audit/logs is reachable) but not
     * `company.update` (so PATCH /settings/company is forbidden), proving
     * the resolution is the role's real matrix, not a hardcoded list.
     */
    public function test_a_custom_role_assigned_to_a_user_resolves_end_to_end(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        $custom = Role::query()->create(['name' => 'auditor', 'label' => 'Auditor', 'is_system' => false]);
        $custom->syncPermissions(['audit.view', 'reports.view']);

        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'auditor']);
        $this->actingAs($user);

        $this->get('/audit/logs')->assertOk();
        $this->patch('/settings/company', [
            'name' => 'SWECOM PLC', 'location' => 'X', 'phone' => '676',
            'reconnection_fine' => '2000', 'arrears_second_approval_threshold' => '20000',
        ])->assertStatus(403);
    }

    /**
     * SettingsUserController::store()'s two-connection insert, relocated to
     * UserController — same "release the outer transactions, do real
     * committed work, clean up in finally" strategy as the original
     * SettingsTest version (see InteractsWithTenantRoles for the
     * cross-connection-visibility reason).
     */
    public function test_super_can_create_a_new_user_and_it_is_queryable_afterward(): void
    {
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
            $this->actingAs($user)->post('/users', [
                'name' => 'New Test User',
                'username' => 'newuctuser',
                'email' => 'newuctuser@example.test',
                'password' => 'password123',
                'role' => 'agent',
            ])->assertRedirect(route('users.index'))->assertSessionHas('success');

            $createdUser = User::query()->where('email', 'newuctuser@example.test')->first();
            $this->assertNotNull($createdUser, 'The new central user row was not created.');
            $createdUserId = $createdUser->id;

            tenancy()->initialize(Tenant::find('swecom'));
            $this->assertDatabaseHas('tenant_users', ['user_id' => $createdUser->id, 'role' => 'agent']);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            if ($createdUserId !== null) {
                TenantUser::query()->where('user_id', $createdUserId)->delete();
            }
            TenantUser::query()->where('user_id', $user->id)->update(['role' => $originalRole]);

            DB::connection('tenant')->beginTransaction();

            if ($createdUserId !== null) {
                User::query()->whereKey($createdUserId)->delete();
            }

            DB::connection('pgsql')->beginTransaction();
        }
    }
}
