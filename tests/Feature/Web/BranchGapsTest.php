<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\CustomerBranchMove;
use App\Models\SalaryGrade;
use App\Models\StaffSalary;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Services\CashBookService;
use App\Services\OpeningBalanceService;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The gaps closed after the branches v2 merge: what a manager fenced to one
 * branch may no longer reach (zones, staff, company-wide settings, opening
 * balances, salaries), and money that stays inside its own branch.
 */
class BranchGapsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $headquarters;

    private Branch $buea;

    private User $kelvin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();
        $this->headquarters = Branch::query()->where('is_headquarters', true)->sole();
        $this->buea = BranchFactory::new()->create(['name' => 'Buea']);
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
    }

    public function test_a_fenced_manager_creates_zones_in_their_own_branch_only(): void
    {
        $this->as('admin', $this->buea->id);

        $this->post('/zones', ['name' => 'Molyko', 'code' => 'MLK', 'branch_uuid' => $this->headquarters->uuid])->assertSessionHasErrors('branch_uuid');

        $this->post('/zones', ['name' => 'Molyko', 'code' => 'MLK'])->assertSessionHasNoErrors();
        $this->assertSame($this->buea->id, Zone::query()->where('name', 'MOLYKO')->sole()->branch_id);
    }

    public function test_a_zone_with_customers_keeps_its_branch(): void
    {
        $zone = ZoneFactory::new()->create(['branch_id' => $this->buea->id]);
        CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->as('admin');
        $this->patch("/zones/{$zone->uuid}", ['name' => $zone->name, 'code' => $zone->code, 'branch_uuid' => $this->headquarters->uuid])->assertSessionHasErrors();

        $this->assertSame($this->buea->id, $zone->fresh()->branch_id);
    }

    public function test_a_fenced_manager_cannot_move_staff_out_of_their_branch(): void
    {
        $staff = $this->staff('terence@shalomtech.dev', $this->buea->id);

        $this->as('admin', $this->buea->id);
        $this->patch("/users/{$staff->id}", ['branch_uuid' => ''])->assertSessionHasErrors('branch_uuid');
        $this->patch("/users/{$staff->id}", ['job_title' => 'Head cashier'])->assertSessionHasNoErrors();

        $this->assertSame($this->buea->id, $staff->fresh()->branch_id);
    }

    public function test_a_cashier_whose_till_holds_money_cannot_be_moved_to_another_branch(): void
    {
        $staff = $this->staff('terence@shalomtech.dev', null);
        $cashBook = app(CashBookService::class);
        $till = $cashBook->tillFor($staff->user);
        $cashBook->record($till, 'in', '5000', CashMovement::KIND_OPENING_BALANCE, $this->kelvin);

        $this->as('admin');
        $this->patch("/users/{$staff->id}", ['branch_uuid' => $this->buea->uuid])->assertSessionHasErrors('branch_uuid');
        $this->assertNull($staff->fresh()->branch_id);
    }

    public function test_an_empty_till_with_money_in_transit_stays_open_after_a_move(): void
    {
        $staff = $this->staff('terence@shalomtech.dev', null);
        $cashBook = app(CashBookService::class);
        $till = $cashBook->tillFor($staff->user);
        $cashBook->record($till, 'in', '5000', CashMovement::KIND_OPENING_BALANCE, $this->kelvin);

        // Only the till's holder sends money out of it (wave 3).
        $this->as('admin');
        $this->post('/cash/branch-transfers', ['from_account' => $till->uuid, 'to_branch' => $this->buea->uuid, 'amount' => '5000'])->assertSessionHasErrors('from_account');
        $this->as('admin', null, 'terence@shalomtech.dev');
        $this->post('/cash/branch-transfers', ['from_account' => $till->uuid, 'to_branch' => $this->buea->uuid, 'amount' => '5000'])->assertSessionHasNoErrors();
        $staff->update(['branch_id' => $this->buea->id]);

        $this->assertTrue($cashBook->tillFor($staff->user)->is($till));

        // Refused: the money goes back into the still-open till.
        $transfer = BranchTransfer::query()->latest('id')->firstOrFail();
        $this->as('admin', $this->buea->id);
        $this->post("/cash/branch-transfers/{$transfer->uuid}/reject", ['reason' => 'Not ours'])->assertSessionHasNoErrors();
        $this->as('admin');
        $this->assertSame('5000.00', $cashBook->balance($till));
    }

    public function test_company_wide_settings_belong_to_headquarters(): void
    {
        $this->as('admin', $this->buea->id);
        $this->post('/users/roles', ['label' => 'Fiber Technician', 'permissions' => []])->assertForbidden();

        $this->as('admin', $this->headquarters->id);
        $this->post('/users/roles', ['label' => 'Fiber Technician', 'permissions' => []])->assertSessionHasNoErrors();
    }

    public function test_only_headquarters_submits_the_opening_balances(): void
    {
        $batch = app(OpeningBalanceService::class)->start(Carbon::now()->addMonthNoOverflow()->format('Y-m'), $this->kelvin);

        $this->as('admin', $this->buea->id);
        $this->post("/opening-balances/{$batch->uuid}/submit")->assertForbidden();
        $this->patch("/opening-balances/{$batch->uuid}", ['go_live_period' => Carbon::now()->addMonthsNoOverflow(2)->format('Y-m')])->assertForbidden();
    }

    public function test_a_customer_who_moves_branch_is_recorded_from_the_next_period(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->headquarters->id])->id]);
        $bueaZone = ZoneFactory::new()->create(['branch_id' => $this->buea->id]);

        $this->as('admin');
        $customer->update(['zone_id' => $bueaZone->id]);

        $move = CustomerBranchMove::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame($this->headquarters->id, $move->from_branch_id);
        $this->assertSame($this->buea->id, $move->to_branch_id);
        $this->assertSame(Carbon::createFromFormat('!Y-m', BusinessTimezone::currentPeriod())->addMonthNoOverflow()->format('Y-m'), $move->effective_period);
        $this->assertSame($this->buea->id, Customer::query()->whereKey($customer->id)->value('branch_id'));
    }

    public function test_nobody_sets_or_signs_off_their_own_salary(): void
    {
        $grade = SalaryGrade::query()->create(['name' => 'Technician', 'min_amount' => 50000, 'max_amount' => 80000]);
        $retired = SalaryGrade::query()->create(['name' => 'Old scale', 'min_amount' => 10000, 'max_amount' => 20000, 'is_active' => false]);
        $own = TenantUser::query()->where('user_id', $this->kelvin->id)->sole();
        $staff = $this->staff('terence@shalomtech.dev', null);

        $this->as('admin');
        $this->post("/users/{$own->id}/salary", ['amount' => '500000'])->assertForbidden();
        $this->post("/users/{$staff->id}/salary", ['amount' => '15000', 'grade_uuid' => $retired->uuid])->assertSessionHasErrors('grade_uuid');

        // An admin who proposed an out-of-range salary cannot approve it too.
        $this->post("/users/{$staff->id}/salary", ['amount' => '95000', 'grade_uuid' => $grade->uuid])->assertSessionHasNoErrors();
        $pending = StaffSalary::query()->where('user_id', $staff->user_id)->latest('id')->firstOrFail();
        $this->assertSame(StaffSalary::STATUS_PENDING, $pending->status);

        $this->post("/settings/salaries/{$pending->uuid}/approve")->assertSessionHasErrors('salary');
        $this->assertSame(StaffSalary::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_a_fenced_manager_hands_receipt_books_to_their_branch_staff_only(): void
    {
        $bueaStaff = $this->staff('terence@shalomtech.dev', $this->buea->id);
        $hqStaff = $this->staff('patience@shalomtech.dev', null);

        $this->as('admin', $this->buea->id);

        $this->get('/receipt-stock')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('holders', fn ($holders) => collect($holders)->pluck('id')->contains($bueaStaff->user_id)
                && ! collect($holders)->pluck('id')->contains($hqStaff->user_id)));
    }

    private function staff(string $email, ?int $branchId): TenantUser
    {
        $tenantUser = TenantUser::query()->where('user_id', User::query()->where('email', $email)->value('id'))->firstOrFail();
        $tenantUser->update(['role' => 'cashier', 'branch_id' => $branchId]);

        return $tenantUser;
    }

    /** Acts as kelvin, or as $email — a second person where the sender may not receive (wave 3). */
    private function as(string $role, ?int $branchId = null, ?string $email = null): void
    {
        $user = $email !== null ? User::query()->where('email', $email)->firstOrFail() : $this->kelvin;
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);
    }
}
