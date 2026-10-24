<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Agent;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CashBookService;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\ExpenseCategoryFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Branches v2 foundation (docs/plans/branches-v2.md §5): money-out records
 * its branch on the server — a bon from its requester's fence (agents
 * through their zone), its expenditures from the bon, a till from its
 * holder — and nothing a request sends can choose it.
 */
class MoneyOutBranchTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private ExpenseCategory $category;

    private Branch $mainBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();

        $this->category = ExpenseCategoryFactory::new()->create(['name' => 'Fuel']);
        $this->mainBranch = Branch::query()->where('name', 'Main Branch')->firstOrFail();
        TenantUser::query()->where('tenant_id', 'swecom')->update(['is_investor' => false]);
        Company::query()->update(['voucher_approval_limit' => '200000', 'voucher_daily_limit' => null, 'monthly_spending_cap' => null]);
        Company::forgetCache();
    }

    private function as(string $email, string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        $this->actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $extra */
    private function request(array $extra = []): Voucher
    {
        $this->post('/vouchers', [
            'kind' => 'expense',
            'payee_name' => 'Total Kumba',
            'purpose' => 'Fuel for the generator',
            'category_uuid' => $this->category->uuid,
            'amount' => '20000',
            ...$extra,
        ])->assertSessionHasNoErrors();

        return Voucher::query()->latest('id')->firstOrFail();
    }

    private function approveAndPay(Voucher $voucher): Voucher
    {
        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertSessionHasNoErrors();

        $cashier = $this->as('patience@shalomtech.dev', 'cashier');
        $till = app(CashBookService::class)->tillFor($cashier);
        app(CashBookService::class)->record($till, 'in', '500000', CashMovement::KIND_OPENING_BALANCE, $cashier);

        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till->uuid, 'method' => 'cash', 'received_by_name' => 'Total station attendant'])
            ->assertSessionHasNoErrors();

        return $voucher->refresh();
    }

    /** A seeded staff member with no till yet (any existing one is detached inside the test's transaction). */
    private function cashierWithoutTill(string $email, ?int $branchId): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'cashier', 'branch_id' => $branchId]);
        CashAccount::query()->where('holder_user_id', $user->id)->update(['holder_user_id' => null]);

        return $user;
    }

    public function test_main_branch_is_the_one_headquarters_and_cannot_be_deleted(): void
    {
        $this->assertTrue($this->mainBranch->is_headquarters);
        $this->assertSame($this->mainBranch->id, Branch::headquartersId());

        $this->as('kelvin@shalomtech.dev', 'admin');
        $this->delete("/branches/{$this->mainBranch->uuid}")->assertSessionHas('error', 'Headquarters cannot be deleted.');
        $this->assertNotNull($this->mainBranch->fresh());
    }

    public function test_existing_money_out_was_backfilled_to_headquarters(): void
    {
        foreach (['vouchers', 'expenditures', 'cash_accounts'] as $table) {
            $this->assertSame(0, DB::connection('tenant')->table($table)->whereNull('branch_id')->count(), $table);
        }
    }

    public function test_an_unfenced_bon_and_its_expenditure_belong_to_headquarters(): void
    {
        $this->as('kelvin@shalomtech.dev', 'agent');
        $voucher = $this->approveAndPay($this->request());

        $this->assertSame($this->mainBranch->id, $voucher->branch_id);
        $this->assertSame($this->mainBranch->id, Expenditure::query()->findOrFail($voucher->expenditure_id)->branch_id);
    }

    public function test_a_fenced_requester_tags_the_bon_with_their_branch_and_the_request_cannot_choose(): void
    {
        $buea = BranchFactory::new()->create(['name' => 'Buea']);

        $this->as('kelvin@shalomtech.dev', 'manager', $buea->id);
        $voucher = $this->request(['branch_id' => $this->mainBranch->id, 'branch_uuid' => $this->mainBranch->uuid]);

        $this->assertSame($buea->id, $voucher->branch_id);
    }

    public function test_an_agent_takes_the_branch_of_their_zone(): void
    {
        $buea = BranchFactory::new()->create(['name' => 'Buea']);
        $zone = ZoneFactory::new()->create(['branch_id' => $buea->id]);
        $agentUser = $this->as('kelvin@shalomtech.dev', 'agent');

        if (Agent::query()->where('user_id', $agentUser->id)->update(['zone_id' => $zone->id]) === 0) {
            AgentFactory::new()->create(['user_id' => $agentUser->id, 'zone_id' => $zone->id]);
        }

        $voucher = $this->request();

        $this->assertSame($buea->id, $voucher->branch_id);
    }

    public function test_with_several_branches_an_unfenced_bon_is_still_headquarters(): void
    {
        BranchFactory::new()->create(['name' => 'Buea']);

        $this->as('kelvin@shalomtech.dev', 'admin');
        $voucher = $this->request();

        $this->assertSame($this->mainBranch->id, $voucher->branch_id);
    }

    public function test_a_correction_carries_the_bon_branch(): void
    {
        $this->as('kelvin@shalomtech.dev', 'agent');
        $voucher = $this->approveAndPay($this->request());

        $this->as('godlove@shalomtech.dev', 'admin');
        $this->post("/vouchers/{$voucher->uuid}/correct", ['outcome' => 'returned', 'amount' => 5000, 'reason' => 'Price was lower'])
            ->assertSessionHasNoErrors();

        $adjustment = Expenditure::query()->where('voucher_id', $voucher->id)->where('source', Expenditure::SOURCE_ADJUSTMENT)->sole();
        $this->assertSame($this->mainBranch->id, $adjustment->branch_id);
    }

    public function test_a_till_takes_its_holder_branch_and_company_accounts_are_headquarters(): void
    {
        $buea = BranchFactory::new()->create(['name' => 'Buea']);
        $cashBook = app(CashBookService::class);

        $this->assertSame($buea->id, $cashBook->tillFor($this->cashierWithoutTill('blessing@shalomtech.dev', $buea->id))->branch_id);
        $this->assertSame($this->mainBranch->id, $cashBook->tillFor($this->cashierWithoutTill('godlove@shalomtech.dev', null))->branch_id);
        $this->assertSame($this->mainBranch->id, $cashBook->createAccount(CashAccount::KIND_BANK, 'Afriland', null, '0001')->branch_id);

        CashAccount::query()->where('kind', CashAccount::KIND_MOBILE_MONEY)->update(['is_active' => false]);
        $this->assertSame($this->mainBranch->id, $cashBook->walletFor('mtn_momo')->branch_id);
    }
}
