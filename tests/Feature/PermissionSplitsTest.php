<?php

namespace Tests\Feature;

use App\Auth\Permission;
use App\Auth\PermissionSplits;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Wave 2 of the permission audit (owner, 2026-11-02): every feature has its
 * own permission — no borrowed checks, no role names — and a role the owner
 * creates sees and does exactly what it is ticked for.
 */
class PermissionSplitsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingWithRole(string $name, array $permissions): User
    {
        Role::query()->create(['name' => $name, 'label' => ucfirst($name), 'is_system' => false])->syncPermissions($permissions);
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $name, 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }

    public function test_each_split_permission_starts_with_whoever_could_do_it_before(): void
    {
        $manager = DefaultRolesSeeder::definitions()['manager']['permissions'];

        foreach (PermissionSplits::FROM as $permission => $sources) {
            $this->assertSame(
                array_intersect($sources, $manager) !== [],
                in_array($permission, $manager, true),
                "{$permission} for the manager role",
            );
        }

        // Every new permission is a real catalog entry.
        foreach ([...array_keys(PermissionSplits::FROM), ...PermissionSplits::ADMIN_ONLY] as $permission) {
            $this->assertNotNull(Permission::tryFrom($permission), $permission);
        }
    }

    public function test_installation_work_no_longer_opens_compensation(): void
    {
        $this->actingWithRole('technician', ['customers.view', 'installations.manage', 'installations.approve']);

        $this->get('/compensation')->assertForbidden();
    }

    public function test_a_founders_role_sees_compensation_only_when_ticked_and_acts_only_when_ticked(): void
    {
        $this->actingWithRole('founders', ['compensation.view']);

        $this->get('/compensation')->assertOk();
        $this->post('/compensation', [])->assertForbidden();
    }

    // One role per test: a controller keeps the tenant context it was built
    // with for the life of the test's app instance.

    public function test_viewing_manuscripts_no_longer_opens_the_phone_app(): void
    {
        $this->actingWithRole('collector', ['customers.view', 'manuscripts.view']);
        $this->get('/agent-app')->assertForbidden();
    }

    public function test_the_phone_app_is_its_own_switch(): void
    {
        $this->actingWithRole('field', ['mobile.use_app']);
        $this->get('/agent-app')->assertOk();
    }

    public function test_audit_view_no_longer_opens_the_arrears_queue(): void
    {
        $this->actingWithRole('auditor', ['audit.view']);
        $this->get('/arrears-adjustments')->assertForbidden();
    }

    public function test_view_arrears_opens_the_arrears_queue(): void
    {
        $this->actingWithRole('arrears-viewer', ['arrears.view']);
        $this->get('/arrears-adjustments')->assertOk();
    }
}
