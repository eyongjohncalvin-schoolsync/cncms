<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Agent;
use App\Models\Scopes\BranchScope;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\AgentFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Users page (owner, 2026-10-26): an account with no history can be
 * deleted; anyone with history can only be deactivated, and a deactivated
 * user can be reactivated (StaffRemovalService).
 *
 * kelvin (seeded owner) acts as super; patience (seeded member) is the
 * target where nothing has to be committed.
 */
class StaffRemovalTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    private User $patience;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->patience = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => 'super', 'branch_id' => null]);
    }

    public function test_a_deactivated_user_can_be_reactivated(): void
    {
        $this->patience->forceFill(['status' => 'passive'])->save();
        $membership = TenantUser::query()->where('user_id', $this->patience->id)->firstOrFail();

        $this->actingAs($this->kelvin)
            ->post("/users/{$membership->id}/activate")
            ->assertRedirect(route('users.index'));

        $this->assertSame('active', $this->patience->fresh()->status);
    }

    public function test_a_user_with_history_cannot_be_deleted(): void
    {
        DB::table('audit_logs')->insert([
            'tenant_id' => 'swecom', 'user_id' => $this->patience->id, 'action' => 'update',
            'table_name' => 'customers', 'record_uuid' => (string) Str::uuid(), 'created_at' => now(),
        ]);
        $membership = TenantUser::query()->where('user_id', $this->patience->id)->firstOrFail();

        $this->actingAs($this->kelvin)->get('/users')->assertInertia(fn ($page) => $page
            ->where('users', fn ($users) => collect($users)->firstWhere('id', $membership->id)['can_delete'] === false));

        $this->delete("/users/{$membership->id}")->assertSessionHasErrors('user');

        $this->assertDatabaseHas('tenant_users', ['id' => $membership->id]);
    }

    public function test_you_cannot_delete_yourself(): void
    {
        $membership = TenantUser::query()->where('user_id', $this->kelvin->id)->firstOrFail();

        $this->actingAs($this->kelvin)->delete("/users/{$membership->id}")->assertSessionHasErrors('user');

        $this->assertDatabaseHas('tenant_users', ['id' => $membership->id]);
    }

    public function test_an_agent_cannot_delete_users(): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => 'agent']);
        $membership = TenantUser::query()->where('user_id', $this->patience->id)->firstOrFail();

        $this->actingAs($this->kelvin)->delete("/users/{$membership->id}")->assertForbidden();
    }

    /**
     * Commits real rows: the central user and its tenant membership are on
     * two database sessions (see InteractsWithTenantRoles), so the same
     * "release the outer transactions, clean up in finally" strategy as
     * UsersControlCenterTest's create test.
     */
    public function test_an_account_with_no_history_is_deleted_with_its_setup(): void
    {
        DB::connection('tenant')->rollBack();
        tenancy()->end();
        DB::connection('pgsql')->rollBack();

        tenancy()->initialize(Tenant::find('swecom'));
        $this->removeLeftovers();
        $originalRole = TenantUser::query()->where('user_id', $this->kelvin->id)->value('role');
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => 'super']);
        tenancy()->end();

        try {
            $this->actingAs($this->kelvin)->post('/users', [
                'name' => 'Mistake Account', 'username' => 'mistakeaccount', 'email' => 'mistake@example.test',
                'password' => 'password123', 'role' => 'agent',
            ])->assertRedirect(route('users.index'));

            $created = User::query()->where('email', 'mistake@example.test')->firstOrFail();
            tenancy()->initialize(Tenant::find('swecom'));
            AgentFactory::new()->create(['user_id' => $created->id, 'zone_id' => Zone::query()->withoutGlobalScope(BranchScope::class)->value('id')]);
            $membership = TenantUser::query()->where('user_id', $created->id)->firstOrFail();
            $this->assertDatabaseHas('staff_assignments', ['user_id' => $created->id]);

            $this->get('/users')->assertInertia(fn ($page) => $page
                ->where('users', fn ($users) => collect($users)->firstWhere('id', $membership->id)['can_delete'] === true));

            $this->delete("/users/{$membership->id}")
                ->assertRedirect(route('users.index'))
                ->assertSessionHasNoErrors();

            $this->assertNull(User::query()->find($created->id), 'The central account is gone.');
            $this->assertDatabaseMissing('tenant_users', ['user_id' => $created->id]);
            $this->assertDatabaseMissing('staff_assignments', ['user_id' => $created->id]);
            $this->assertFalse(Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $created->id)->exists());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            $this->removeLeftovers();
            TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $originalRole]);

            DB::connection('tenant')->beginTransaction();
            DB::connection('pgsql')->beginTransaction();
        }
    }

    /**
     * A run killed before its finally would leave the account behind.
     */
    private function removeLeftovers(): void
    {
        $ids = User::query()->where('email', 'mistake@example.test')->orWhere('username', 'mistakeaccount')->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $agentIds = Agent::query()->withoutGlobalScope(BranchScope::class)->whereIn('user_id', $ids)->pluck('id');
        DB::table('zone_agents')->whereIn('agent_id', $agentIds)->delete();
        Agent::query()->withoutGlobalScope(BranchScope::class)->whereKey($agentIds)->delete();
        DB::table('staff_assignments')->whereIn('user_id', $ids)->delete();
        TenantUser::query()->whereIn('user_id', $ids)->delete();
        User::query()->whereKey($ids)->delete();
    }
}
