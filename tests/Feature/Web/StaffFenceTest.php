<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\DataTransferObjects\AgentData;
use App\Models\Branch;
use App\Models\StaffAssignment;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AgentService;
use App\Support\TenantContext;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Branches v2 P4a (docs/plans/branches-v2.md §4.4, D7): someone fenced to a
 * branch manages that branch's staff only — sees, hires into, edits and
 * deactivates them — and never moves staff between branches, grants investor
 * status or the owner role. Every hire, transfer and role change is kept in
 * the staff assignment history.
 */
class StaffFenceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $buea;

    private User $kelvin;

    private TenantUser $bueaStaff;

    private TenantUser $hqStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->buea = BranchFactory::new()->create(['name' => 'Buea']);
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        $this->bueaStaff = $this->staff('terence@shalomtech.dev', 'cashier', $this->buea->id);
        $this->hqStaff = $this->staff('patience@shalomtech.dev', 'cashier', null);
    }

    public function test_every_current_staff_member_has_an_open_assignment(): void
    {
        $withoutOpen = TenantUser::query()
            ->whereNotIn('user_id', StaffAssignment::query()->whereNull('ends_on')->select('user_id'))
            ->count();

        $this->assertSame(0, $withoutOpen);
    }

    public function test_a_fenced_manager_sees_and_edits_their_branch_staff_only(): void
    {
        $this->as('admin', $this->buea->id);

        $this->get('/users')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('fenced', true)
            ->where('users', fn ($rows) => collect($rows)->pluck('id')->contains($this->bueaStaff->id)
                && ! collect($rows)->pluck('id')->contains($this->hqStaff->id)));

        $this->patch("/users/{$this->bueaStaff->id}", ['job_title' => 'Head cashier'])->assertSessionHasNoErrors();
        $this->patch("/users/{$this->hqStaff->id}", ['job_title' => 'Nope'])->assertForbidden();
        $this->post("/users/{$this->hqStaff->id}/deactivate")->assertForbidden();
    }

    public function test_a_fenced_manager_cannot_move_staff_grant_investor_or_the_owner_role(): void
    {
        $this->as('admin', $this->buea->id);

        $this->patch("/users/{$this->bueaStaff->id}", ['branch_uuid' => null])->assertSessionHasErrors('branch_uuid');
        $this->patch("/users/{$this->bueaStaff->id}", ['is_investor' => true])->assertSessionHasErrors('is_investor');
        $this->patch("/users/{$this->bueaStaff->id}", ['role' => 'super'])->assertSessionHasErrors('role');

        $this->bueaStaff->refresh();
        $this->assertSame($this->buea->id, $this->bueaStaff->branch_id);
        $this->assertFalse((bool) $this->bueaStaff->is_investor);
        $this->assertSame('cashier', $this->bueaStaff->role);
    }

    public function test_a_transfer_and_a_role_change_are_kept_in_the_history(): void
    {
        $this->as('admin');

        $this->patch("/users/{$this->hqStaff->id}", ['branch_uuid' => $this->buea->uuid])->assertSessionHasNoErrors();
        $this->patch("/users/{$this->hqStaff->id}", ['role' => 'manager'])->assertSessionHasNoErrors();

        $history = StaffAssignment::query()->where('user_id', $this->hqStaff->user_id)->orderBy('id')->get();
        $open = $history->whereNull('ends_on')->sole();

        $this->assertSame($this->buea->id, $open->branch_id);
        $this->assertSame('manager', $open->role);
        $this->assertTrue($history->contains(fn (StaffAssignment $row): bool => $row->branch_id === $this->buea->id && $row->role === 'cashier'));
    }

    public function test_an_agent_moved_to_another_branchs_zone_is_a_transfer(): void
    {
        $this->as('admin');
        $agent = AgentFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => Branch::headquartersId()])->id, 'user_id' => $this->hqStaff->user_id]);
        $bueaZone = ZoneFactory::new()->create(['branch_id' => $this->buea->id]);

        app(AgentService::class)->update($agent, AgentData::fromArray(['zone_uuid' => $bueaZone->uuid]));

        $open = StaffAssignment::query()->where('user_id', $this->hqStaff->user_id)->whereNull('ends_on')->sole();
        $this->assertSame($this->buea->id, $open->branch_id);
        $this->assertSame('agent', $open->role);
    }

    /**
     * Hiring commits a central user row, so — like UsersControlCenterTest's
     * creation test — this runs outside the per-test transactions and cleans
     * up after itself.
     */
    public function test_a_fenced_manager_hires_into_their_own_branch(): void
    {
        DB::connection('tenant')->rollBack();
        tenancy()->end();
        DB::connection('pgsql')->rollBack();

        tenancy()->initialize(Tenant::find('swecom'));
        $kelvinTenantUser = TenantUser::query()->where('user_id', $this->kelvin->id)->firstOrFail();
        $original = ['role' => $kelvinTenantUser->role, 'branch_id' => $kelvinTenantUser->branch_id];
        $branch = BranchFactory::new()->create(['name' => 'Kumba-hire-test']);
        $kelvinTenantUser->update(['role' => 'admin', 'branch_id' => $branch->id]);
        tenancy()->end();

        $createdUserId = null;

        try {
            $this->actingAs($this->kelvin)->post('/users', ['name' => 'Branch Hire', 'username' => 'branchhiretest', 'email' => 'branchhiretest@example.test', 'password' => 'password123', 'role' => 'cashier', 'branch_uuid' => null])
                ->assertSessionHasErrors('branch_uuid');

            $this->actingAs($this->kelvin)->post('/users', ['name' => 'Branch Hire', 'username' => 'branchhiretest', 'email' => 'branchhiretest@example.test', 'password' => 'password123', 'role' => 'cashier'])
                ->assertSessionHasNoErrors();

            $createdUserId = User::query()->where('email', 'branchhiretest@example.test')->value('id');
            $this->assertNotNull($createdUserId);

            tenancy()->initialize(Tenant::find('swecom'));
            $this->assertSame($branch->id, TenantUser::query()->where('user_id', $createdUserId)->value('branch_id'));
            $this->assertSame($branch->id, StaffAssignment::query()->where('user_id', $createdUserId)->whereNull('ends_on')->value('branch_id'));
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            if ($createdUserId !== null) {
                StaffAssignment::query()->where('user_id', $createdUserId)->delete();
                TenantUser::query()->where('user_id', $createdUserId)->delete();
            }

            TenantUser::query()->where('user_id', $this->kelvin->id)->update($original);
            StaffAssignment::query()->where('branch_id', $branch->id)->delete();
            $branch->delete();

            DB::connection('tenant')->beginTransaction();

            if ($createdUserId !== null) {
                User::query()->whereKey($createdUserId)->delete();
            }

            DB::connection('pgsql')->beginTransaction();
        }
    }

    private function staff(string $email, string $role, ?int $branchId): TenantUser
    {
        $tenantUser = TenantUser::query()->where('user_id', User::query()->where('email', $email)->value('id'))->firstOrFail();
        $tenantUser->update(['role' => $role, 'branch_id' => $branchId]);

        return $tenantUser;
    }

    private function as(string $role, ?int $branchId = null): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($this->kelvin);
    }
}
