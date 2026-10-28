<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Agent;
use App\Models\Branch;
use App\Models\SalaryGrade;
use App\Models\StaffSalary;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Branches v2 P4b (docs/plans/branches-v2.md §4.5, D8): headquarters' grades
 * set the range; a salary inside its grade's range is approved at once,
 * outside it waits for headquarters while the current salary stays; a branch
 * sets salaries for its own staff only; agents' salaries follow the same rules.
 */
class SalaryTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $buea;

    private User $kelvin;

    private TenantUser $bueaStaff;

    private TenantUser $hqStaff;

    private SalaryGrade $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->buea = BranchFactory::new()->create(['name' => 'Buea']);
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->bueaStaff = $this->staff('terence@shalomtech.dev', $this->buea->id);
        $this->hqStaff = $this->staff('patience@shalomtech.dev', null);
        $this->technician = SalaryGrade::query()->create(['name' => 'Technician', 'min_amount' => 50000, 'max_amount' => 80000]);
    }

    public function test_existing_agent_salaries_were_carried_in_as_approved(): void
    {
        $missing = Agent::query()->withoutGlobalScopes()->whereNotNull('user_id')->where('salary', '>', 0)
            ->whereNotIn('user_id', StaffSalary::query()->where('status', StaffSalary::STATUS_APPROVED)->select('user_id'))
            ->count();

        $this->assertSame(0, $missing);
    }

    public function test_a_salary_inside_its_grade_is_approved_at_once(): void
    {
        $this->as('manager', $this->buea->id);

        $this->setSalary($this->bueaStaff, '60000', $this->technician)->assertSessionHas('success', 'Salary saved.');

        $this->assertSame(StaffSalary::STATUS_APPROVED, $this->latest($this->bueaStaff)->status);
    }

    public function test_a_salary_outside_its_grade_waits_for_headquarters_who_decides(): void
    {
        // A branch's own administrator (fenced): sees its staff list, sets salaries.
        $this->as('admin', $this->buea->id);
        $this->setSalary($this->bueaStaff, '60000', $this->technician);
        $this->setSalary($this->bueaStaff, '95000', $this->technician);

        $pending = $this->latest($this->bueaStaff);
        $this->assertSame(StaffSalary::STATUS_PENDING, $pending->status);

        // A branch cannot approve its own out-of-range salary.
        $this->post("/settings/salaries/{$pending->uuid}/approve")->assertForbidden();

        $this->get('/users')->assertInertia(fn (Assert $page) => $page
            ->where('users', fn ($rows) => ($row = collect($rows)->firstWhere('id', $this->bueaStaff->id)) !== null
                && $row['salary']['current'] === '60000.00'
                && $row['salary']['pending']['amount'] === '95000.00'));

        // Headquarters decides — here the owner, who may also sign off a salary they proposed.
        $this->as('super');
        $this->get('/settings/salaries')->assertOk()->assertInertia(fn (Assert $page) => $page->where('pending.0.uuid', $pending->uuid));
        $this->post("/settings/salaries/{$pending->uuid}/approve")->assertSessionHasNoErrors();

        $this->assertSame(StaffSalary::STATUS_APPROVED, $pending->fresh()->status);
    }

    public function test_without_a_grade_a_salary_needs_approval_once_grades_exist(): void
    {
        $this->as('manager', $this->buea->id);

        $this->setSalary($this->bueaStaff, '60000', null);

        $this->assertSame(StaffSalary::STATUS_PENDING, $this->latest($this->bueaStaff)->status);
    }

    public function test_a_branch_sets_salaries_for_its_own_staff_only_and_hq_keeps_the_grades(): void
    {
        $this->as('manager', $this->buea->id);

        $this->setSalary($this->hqStaff, '60000', $this->technician, expectOk: false)->assertForbidden();
        $this->post('/settings/salaries/grades', ['name' => 'Branch grade', 'min_amount' => 1, 'max_amount' => 2])->assertForbidden();
        $this->get('/settings/salaries')->assertForbidden();
    }

    public function test_a_newer_salary_replaces_one_still_waiting(): void
    {
        $this->as('manager', $this->buea->id);
        $this->setSalary($this->bueaStaff, '95000', $this->technician);
        $this->setSalary($this->bueaStaff, '99000', $this->technician);

        $this->assertSame(1, StaffSalary::query()->where('user_id', $this->bueaStaff->user_id)->where('status', StaffSalary::STATUS_PENDING)->count());
        $this->assertSame('99000.00', (string) $this->latest($this->bueaStaff)->amount);
    }

    public function test_an_agents_salary_follows_the_rules_and_mirrors_on_the_agent(): void
    {
        $this->as('admin');
        $agent = AgentFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->buea->id])->id, 'user_id' => $this->bueaStaff->user_id, 'salary' => 60000]);
        $this->setSalary($this->bueaStaff, '60000', $this->technician);

        // A field agent's salary is set on the Users page like anyone's (owner,
        // 2026-10-28). Inside the range: approved, and the agent record shows it.
        $this->setSalary($this->bueaStaff, '70000', $this->technician);
        $this->assertSame('70000.00', (string) $agent->fresh()->salary);

        // Outside: waits for headquarters; the agent keeps the approved salary.
        $this->setSalary($this->bueaStaff, '120000', $this->technician);
        $this->assertSame('70000.00', (string) $agent->fresh()->salary);
        $this->assertSame(StaffSalary::STATUS_PENDING, $this->latest($this->bueaStaff)->status);

        $this->as('super');
        $this->post('/settings/salaries/'.$this->latest($this->bueaStaff)->uuid.'/approve')->assertSessionHasNoErrors();
        $this->assertSame('120000.00', (string) $agent->fresh()->salary);
    }

    private function setSalary(TenantUser $tenantUser, string $amount, ?SalaryGrade $grade, bool $expectOk = true): TestResponse
    {
        $response = $this->post("/users/{$tenantUser->id}/salary", array_filter([
            'amount' => $amount,
            'grade_uuid' => $grade?->uuid,
        ]));

        return $expectOk ? $response->assertSessionHasNoErrors() : $response;
    }

    private function latest(TenantUser $tenantUser): StaffSalary
    {
        return StaffSalary::query()->where('user_id', $tenantUser->user_id)->latest('id')->firstOrFail();
    }

    private function staff(string $email, ?int $branchId): TenantUser
    {
        $tenantUser = TenantUser::query()->where('user_id', User::query()->where('email', $email)->value('id'))->firstOrFail();
        $tenantUser->update(['role' => 'cashier', 'branch_id' => $branchId]);

        return $tenantUser;
    }

    private function as(string $role, ?int $branchId = null): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($this->kelvin);
    }
}
