<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Auth\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * RBAC v2 Wave 1 (docs/plans/rbac-v2-configurable-roles.md) — proves the
 * role→permission resolution wiring works end to end WITHOUT any Policy
 * change: the 5 seeded system roles resolve to exactly their day-1
 * permission sets, `super` bypasses, a hand-built custom role resolves to
 * its own list, TenantContext::can()/canAny() agree with the Gate, and the
 * `permissions` key reaches both the Inertia share and GET /auth/me.
 *
 * Runs against a disposable tenant, not real `tenantswecom` — provisioning
 * runs the new create-roles migration + DefaultRolesSeeder, so the schema
 * is a faithful copy of what `tenants:migrate` will produce on swecom with
 * zero risk to real data (UsesDisposableTenant's class doc).
 */
class RolePermissionResolutionTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDisposableTenant;

    private Tenant $tenant;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        // DatabaseTransactions opened an uncommitted transaction on the
        // central connection; the disposable tenant's CREATE SCHEMA +
        // migration step run on separate Postgres sessions that can't see
        // it. Commit for real, provision, then commit the users so the
        // teardown cleanup survives the trait's final rollback.
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }

        $this->tenant = $this->provisionDisposableTenant('rprt');

        foreach (['super', 'admin', 'manager', 'agent', 'worker'] as $role) {
            $this->users[$role] = $this->provisionDisposableTenantAdmin($this->tenant, $role);
        }

        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }

        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // Drop the schema first (removes the tenant_users rows whose
        // cross-schema FK points at these central users), then the central
        // rows.
        Tenant::find($this->tenant->id)?->delete();

        foreach ($this->users as $user) {
            TenantUserIndex::query()->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            $user->delete();
        }

        // Give DatabaseTransactions' teardown rollback something to roll back.
        if (DB::connection()->transactionLevel() === 0) {
            DB::connection()->beginTransaction();
        }

        parent::tearDown();
    }

    private function contextFor(string $role): TenantContext
    {
        return TenantContext::resolve(
            TenantUser::query()
                ->where('user_id', $this->users[$role]->id)
                ->where('tenant_id', $this->tenant->id)
                ->firstOrFail()
        );
    }

    public function test_admin_resolves_to_the_entire_catalog(): void
    {
        $context = $this->contextFor('admin');

        $expected = Permission::values();
        $actual = $context->permissions();
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertFalse($context->isSuper());
    }

    public function test_manager_agent_worker_resolve_to_their_day1_sets(): void
    {
        $definitions = DefaultRolesSeeder::definitions();

        foreach (['manager', 'agent', 'worker'] as $role) {
            $context = $this->contextFor($role);

            $expected = $definitions[$role]['permissions'];
            $actual = $context->permissions();
            sort($expected);
            sort($actual);

            $this->assertSame($expected, $actual, "role [{$role}] resolved to the wrong permission set");
            $this->assertFalse($context->isSuper());
        }
    }

    public function test_can_and_can_any_agree_with_the_resolved_list(): void
    {
        $manager = $this->contextFor('manager');

        $this->assertTrue($manager->can('customers.create'));
        $this->assertFalse($manager->can('command_runs.publish'));
        $this->assertFalse($manager->can('not.a.permission'));
        $this->assertTrue($manager->canAny('command_runs.publish', 'customers.create'));
        $this->assertFalse($manager->canAny('command_runs.publish', 'users.manage'));
    }

    public function test_super_bypasses_every_check(): void
    {
        $super = $this->contextFor('super');

        $this->assertTrue($super->isSuper());
        $this->assertSame(['*'], $super->permissions());
        $this->assertTrue($super->can('customers.delete'));
        $this->assertTrue($super->can('anything.not.in.the.catalog'));
        $this->assertTrue($super->canAny('nope.one', 'nope.two'));
    }

    public function test_super_bypass_flows_through_the_gate(): void
    {
        app()->instance(TenantContext::class, $this->contextFor('super'));
        $this->assertTrue($this->users['super']->can('command_runs.publish'));

        app()->instance(TenantContext::class, $this->contextFor('manager'));
        $this->assertTrue($this->users['manager']->can('customers.create'));
        $this->assertFalse($this->users['manager']->can('command_runs.publish'));

        app()->forgetInstance(TenantContext::class);
    }

    public function test_a_custom_role_resolves_to_its_hand_set_list(): void
    {
        $custom = Role::query()->create([
            'name' => 'Auditor',           // normalised to 'auditor' on save
            'label' => 'External Auditor',
            'is_system' => false,
        ]);
        $custom->syncPermissions(['reports.view', 'audit.view', 'not.a.real.permission']);

        // `tenant_users.role` still carries a CHECK constraint pinning it to
        // the 5 system names (tenant_users_role_check) — Wave 3 must
        // drop/replace it before a custom role can actually be assigned.
        // Wave 1 only proves resolution, so use an in-memory membership.
        $context = TenantContext::resolve(new TenantUser([
            'user_id' => $this->users['worker']->id,
            'tenant_id' => $this->tenant->id,
            'role' => 'auditor',
        ]));

        $resolved = $context->permissions();
        sort($resolved);

        $this->assertSame(['audit.view', 'reports.view'], $resolved, 'unknown permission strings must be dropped');
        $this->assertFalse($context->isSuper());
        $this->assertTrue($context->can('audit.view'));
        $this->assertFalse($context->can('reports.export'));
    }

    public function test_inertia_share_carries_the_permission_list(): void
    {
        tenancy()->end();

        $managerShare = $this->actingAs($this->users['manager'])->get('/dashboard');
        $managerShare->assertOk();
        $managerPerms = $managerShare->viewData('page')['props']['auth']['user']['permissions'];
        $this->assertContains('customers.create', $managerPerms);
        $this->assertNotContains('command_runs.publish', $managerPerms);

        $superShare = $this->actingAs($this->users['super'])->get('/dashboard');
        $superShare->assertOk();
        $this->assertSame(['*'], $superShare->viewData('page')['props']['auth']['user']['permissions']);
    }

    public function test_auth_me_carries_the_permission_list(): void
    {
        tenancy()->end();

        $token = $this->users['agent']->createToken('api')->plainTextToken;
        $me = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me');

        $me->assertOk()->assertJsonPath('role', 'agent');
        $perms = $me->json('permissions');
        $this->assertContains('reports.view', $perms);
        $this->assertNotContains('reports.export', $perms);
        $this->assertNotContains('*', $perms);
    }
}
