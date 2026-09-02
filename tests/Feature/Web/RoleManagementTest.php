<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Users Control Center — Roles & Permissions tab (RBAC v2 Wave 3). The
 * role→permission matrix: create/rename/delete custom roles, edit the
 * permission set, and the structural guards (is_super read-only, is_system
 * undeletable, name immutable, catalog closed).
 *
 * Same session-auth pattern as SettingsTest — reuse the seeded owner,
 * flip their role per test. `roles`/`role_permissions` are tenant-schema
 * tables so every mutation here rolls back with the outer transaction.
 */
class RoleManagementTest extends TestCase
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
    // Access
    // -----------------------------------------------------------------

    public function test_matrix_renders_for_admin(): void
    {
        $this->actingAsRole('admin');

        $this->get('/users/roles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('UsersControlCenter/Roles')
                // >= 5, not == 5: this runs against the real `tenantswecom`
                // schema, where an admin may have added custom roles through
                // this very screen. Assert the 5 seeded system roles are all
                // present rather than that nothing else is.
                ->where('roles', fn ($roles) => collect($roles)->pluck('name')
                    ->intersect(['super', 'admin', 'manager', 'agent', 'worker'])
                    ->count() === 5)
                ->has('permissionsByArea'));
    }

    public function test_a_non_roles_manage_user_is_forbidden(): void
    {
        $this->actingAsRole('manager');

        $this->get('/users/roles')->assertStatus(403);
        $this->post('/users/roles', ['name' => 'x', 'label' => 'X'])->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Create / clone
    // -----------------------------------------------------------------

    public function test_admin_can_create_a_custom_role(): void
    {
        $this->actingAsRole('admin');

        $this->post('/users/roles', [
            'name' => 'Recovery-Supervisor',
            'label' => 'Recovery Supervisor',
            'description' => 'Oversees field recovery',
        ])->assertRedirect(route('users.roles.index'));

        $this->assertDatabaseHas('roles', [
            'name' => 'recovery-supervisor', // normalised to lowercase
            'label' => 'Recovery Supervisor',
            'is_system' => false,
            'is_super' => false,
        ]);
    }

    public function test_reserved_and_duplicate_names_are_rejected(): void
    {
        $this->actingAsRole('admin');

        $this->post('/users/roles', ['name' => 'admin', 'label' => 'X'])->assertSessionHasErrors('name');
        $this->post('/users/roles', ['name' => 'Bad Name!', 'label' => 'X'])->assertSessionHasErrors('name');
    }

    public function test_a_new_role_can_be_cloned_from_an_existing_one(): void
    {
        $this->actingAsRole('admin');

        $manager = Role::query()->where('name', 'manager')->firstOrFail();

        $this->post('/users/roles', [
            'name' => 'manager-copy',
            'label' => 'Manager Copy',
            'clone_from' => $manager->uuid,
        ])->assertRedirect(route('users.roles.index'));

        $copy = Role::query()->where('name', 'manager-copy')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            $manager->permissions()->pluck('permission')->all(),
            $copy->permissions()->pluck('permission')->all(),
        );
    }

    // -----------------------------------------------------------------
    // Edit — label + matrix
    // -----------------------------------------------------------------

    public function test_admin_can_rename_a_role_and_replace_its_permission_set(): void
    {
        $this->actingAsRole('admin');

        $role = Role::query()->create(['name' => 'clerk', 'label' => 'Clerk', 'is_system' => false]);
        $role->syncPermissions(['customers.view']);

        $this->patch("/users/roles/{$role->uuid}", [
            'label' => 'Front Desk Clerk',
            'permissions' => ['customers.view', 'payments.view', 'payments.create'],
        ])->assertRedirect(route('users.roles.index'));

        $role->refresh();
        $this->assertSame('Front Desk Clerk', $role->label);
        $this->assertEqualsCanonicalizing(
            ['customers.view', 'payments.view', 'payments.create'],
            $role->permissions()->pluck('permission')->all(),
        );
    }

    public function test_permission_list_rejects_a_string_outside_the_catalog(): void
    {
        $this->actingAsRole('admin');

        $role = Role::query()->create(['name' => 'clerk', 'label' => 'Clerk', 'is_system' => false]);

        $this->patch("/users/roles/{$role->uuid}", [
            'permissions' => ['customers.view', 'totally.made.up'],
        ])->assertSessionHasErrors('permissions.1');

        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_editing_a_roles_matrix_changes_what_its_members_can_do_on_the_next_request(): void
    {
        $owner = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        $role = Role::query()->create(['name' => 'reviewer', 'label' => 'Reviewer', 'is_system' => false]);
        $role->syncPermissions(['audit.view', 'roles.manage']);

        TenantUser::query()->where('user_id', $owner->id)->update(['role' => 'reviewer']);
        $this->actingAs($owner);

        $this->get('/audit/logs')->assertOk();

        // Drop audit.view (keep roles.manage so this user can still save).
        $this->patch("/users/roles/{$role->uuid}", ['permissions' => ['roles.manage']])
            ->assertRedirect(route('users.roles.index'));

        $this->get('/audit/logs')->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Structural guards
    // -----------------------------------------------------------------

    public function test_the_super_role_cannot_be_edited_or_deleted(): void
    {
        $this->actingAsRole('admin');

        $super = Role::query()->where('name', 'super')->firstOrFail();

        $this->patch("/users/roles/{$super->uuid}", ['label' => 'Hijacked'])->assertStatus(403);
        $this->delete("/users/roles/{$super->uuid}")->assertStatus(403);

        $this->assertDatabaseHas('roles', ['name' => 'super', 'label' => 'Owner']);
    }

    public function test_a_system_roles_name_is_immutable_but_its_matrix_is_editable(): void
    {
        $this->actingAsRole('admin');

        $admin = Role::query()->where('name', 'admin')->firstOrFail();

        $this->patch("/users/roles/{$admin->uuid}", [
            'name' => 'administrator-renamed',
            'label' => 'Administrator',
            'permissions' => ['customers.view'],
        ])->assertRedirect(route('users.roles.index'));

        $admin->refresh();
        $this->assertSame('admin', $admin->name); // name ignored — not in the rules
        $this->assertSame(['customers.view'], $admin->permissions()->pluck('permission')->all());
    }

    public function test_a_system_role_cannot_be_deleted(): void
    {
        $this->actingAsRole('admin');

        $worker = Role::query()->where('name', 'worker')->firstOrFail();

        $this->delete("/users/roles/{$worker->uuid}")->assertStatus(403);
        $this->assertDatabaseHas('roles', ['name' => 'worker']);
    }

    // -----------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------

    public function test_deleting_a_custom_role_still_held_by_a_member_is_blocked_and_lists_them(): void
    {
        $owner = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        // The custom role itself carries roles.manage, so the acting user
        // (who holds it) can still attempt the delete — which must fail
        // precisely BECAUSE they still hold it.
        $role = Role::query()->create(['name' => 'temp', 'label' => 'Temp Role', 'is_system' => false]);
        $role->syncPermissions(['roles.manage']);

        TenantUser::query()->where('user_id', $owner->id)->update(['role' => 'temp']);
        $this->actingAs($owner);

        $response = $this->delete("/users/roles/{$role->uuid}");

        $response->assertSessionHasErrors('role');
        $this->assertStringContainsString(
            $owner->name,
            session('errors')->getBag('default')->first('role'),
        );
        $this->assertDatabaseHas('roles', ['name' => 'temp']);
    }

    public function test_an_unused_custom_role_can_be_deleted(): void
    {
        $this->actingAsRole('admin');

        $role = Role::query()->create(['name' => 'disposable', 'label' => 'Disposable', 'is_system' => false]);
        $role->syncPermissions(['customers.view']);

        $this->delete("/users/roles/{$role->uuid}")->assertRedirect(route('users.roles.index'));

        $this->assertDatabaseMissing('roles', ['name' => 'disposable']);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $role->id]);
    }
}
