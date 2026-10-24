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

    public function test_admin_can_create_a_custom_role_from_its_display_name_alone(): void
    {
        $this->actingAsRole('admin');

        $this->post('/users/roles', [
            'label' => 'IT Technician',
            'description' => 'Keeps the head-end and office IT running',
        ])->assertSessionHasNoErrors()->assertRedirect(route('users.roles.index'));

        $this->assertDatabaseHas('roles', [
            'name' => 'it_technician',
            'label' => 'IT Technician',
            'description' => 'Keeps the head-end and office IT running',
            'is_system' => false,
            'is_super' => false,
        ]);
    }

    public function test_the_generated_key_is_an_ascii_underscore_slug(): void
    {
        $this->actingAsRole('admin');

        $this->post('/users/roles', ['label' => '  Human Resource  '])->assertSessionHasNoErrors();
        $this->post('/users/roles', ['label' => 'Secrétaire Générale'])->assertSessionHasNoErrors();
        $this->post('/users/roles', ['label' => 'Base-Technician (Night)'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('roles', ['name' => 'human_resource', 'label' => 'Human Resource']);
        $this->assertDatabaseHas('roles', ['name' => 'secretaire_generale', 'label' => 'Secrétaire Générale']);
        $this->assertDatabaseHas('roles', ['name' => 'base_technician_night']);
    }

    public function test_a_duplicate_display_name_gets_a_numbered_key(): void
    {
        $this->actingAsRole('admin');

        $this->post('/users/roles', ['label' => 'Fiber Technician'])->assertSessionHasNoErrors();
        $this->post('/users/roles', ['label' => 'Fiber Technician'])->assertSessionHasNoErrors();
        $this->post('/users/roles', ['label' => 'fiber technician'])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['fiber_technician', 'fiber_technician_2', 'fiber_technician_3'],
            Role::query()->where('name', 'like', 'fiber_technician%')->pluck('name')->all(),
        );
    }

    public function test_a_key_sent_by_the_client_is_ignored(): void
    {
        $this->actingAsRole('admin');

        $this->post('/users/roles', [
            'name' => 'hand_picked_key',
            'label' => 'Collector',
        ])->assertSessionHasNoErrors()->assertRedirect(route('users.roles.index'));

        $this->assertDatabaseHas('roles', ['name' => 'collector', 'label' => 'Collector']);
        $this->assertDatabaseMissing('roles', ['name' => 'hand_picked_key']);
    }

    public function test_a_client_cannot_overwrite_or_impersonate_a_system_role_by_sending_its_key(): void
    {
        $this->actingAsRole('admin');

        $this->post('/users/roles', ['name' => 'super', 'label' => 'Storekeeper'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('roles', ['name' => 'storekeeper', 'is_super' => false, 'is_system' => false]);
        $this->assertSame(1, Role::query()->where('is_super', true)->count());
    }

    public function test_the_display_name_is_required(): void
    {
        $this->actingAsRole('admin');

        $before = Role::query()->count();

        $this->post('/users/roles', ['label' => ''])->assertSessionHasErrors('label');
        $this->post('/users/roles', ['label' => '   '])->assertSessionHasErrors('label');
        $this->post('/users/roles', ['name' => 'driver'])->assertSessionHasErrors('label');
        $this->post('/users/roles', ['label' => str_repeat('a', 101)])->assertSessionHasErrors('label');

        $this->assertSame($before, Role::query()->count());
    }

    public function test_a_system_role_key_can_never_be_generated(): void
    {
        $this->actingAsRole('admin');

        foreach (['Super', 'Admin', 'Manager', 'Agent', 'Cashier', 'Worker'] as $label) {
            $this->post('/users/roles', ['label' => $label])->assertSessionHasNoErrors();
        }

        foreach (Role::RESERVED_NAMES as $reserved) {
            // The system row is untouched and the custom one got a suffix.
            $this->assertSame(1, Role::query()->where('name', $reserved)->count());
            $this->assertTrue(Role::query()->where('name', $reserved)->value('is_system'));
            $this->assertDatabaseHas('roles', ['name' => "{$reserved}_2", 'is_system' => false, 'is_super' => false]);
        }

        $this->assertSame(1, Role::query()->where('is_super', true)->count());
    }

    public function test_a_reserved_key_is_skipped_even_if_its_system_row_is_missing(): void
    {
        Role::query()->where('name', 'cashier')->where('is_system', true)->delete();

        $this->assertSame('cashier_2', Role::generateUniqueName('Cashier'));
    }

    public function test_a_long_display_name_is_cut_to_the_key_column_length(): void
    {
        $this->actingAsRole('admin');

        $label = 'Senior Customer Service And Complaints Resolution Officer For Kumba';

        $this->post('/users/roles', ['label' => $label])->assertSessionHasNoErrors();
        $this->post('/users/roles', ['label' => $label])->assertSessionHasNoErrors();

        $names = Role::query()->where('label', $label)->orderBy('id')->pluck('name')->all();

        $this->assertCount(2, $names);
        $this->assertSame('senior_customer_service_and_complaints_resolution', $names[0]);
        $this->assertSame('senior_customer_service_and_complaints_resolutio_2', $names[1]);

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(Role::NAME_MAX_LENGTH, strlen($name));
        }
    }

    public function test_a_display_name_with_no_letters_or_digits_still_gets_a_key(): void
    {
        $this->assertMatchesRegularExpression('/^role(_\d+)?$/', Role::generateUniqueName('★ ★ ★'));
    }

    public function test_an_invalid_clone_source_is_a_validation_error_not_a_crash(): void
    {
        $this->actingAsRole('admin');

        $this->post('/users/roles', ['label' => 'Driver', 'clone_from' => 'not-a-uuid'])
            ->assertSessionHasErrors('clone_from');

        $this->assertDatabaseMissing('roles', ['name' => 'driver']);
    }

    public function test_editing_a_custom_role_keeps_its_key(): void
    {
        $this->actingAsRole('admin');

        $this->post('/users/roles', ['label' => 'Supervisor'])->assertSessionHasNoErrors();

        $role = Role::query()->where('name', 'supervisor')->firstOrFail();

        $this->patch("/users/roles/{$role->uuid}", [
            'name' => 'renamed_key',
            'label' => 'Zone Supervisor',
            'permissions' => ['customers.view'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('users.roles.index'));

        $role->refresh();
        $this->assertSame('supervisor', $role->name);
        $this->assertSame('Zone Supervisor', $role->label);
        $this->assertDatabaseMissing('roles', ['name' => 'renamed_key']);
        $this->assertDatabaseMissing('roles', ['name' => 'zone_supervisor']);
    }

    public function test_a_new_role_can_be_cloned_from_an_existing_one(): void
    {
        $this->actingAsRole('admin');

        $manager = Role::query()->where('name', 'manager')->firstOrFail();

        $this->post('/users/roles', [
            'label' => 'Manager Copy',
            'clone_from' => $manager->uuid,
        ])->assertRedirect(route('users.roles.index'));

        $copy = Role::query()->where('name', 'manager_copy')->firstOrFail();
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
