<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Auth\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerCharge;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CustomerRelocationService;
use App\Services\ManuscriptService;
use App\Services\PaymentReceiptService;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Branches v2 P3a (docs/plans/branches-v2.md §4.6, D4): a branch's own contact
 * details, MoMo number and fees, inherited from headquarters when empty,
 * printed on that branch's bills and receipts and charged by its staff; set
 * by whoever manages branches or by the branch's own staff.
 */
class BranchSettingsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $headquarters;

    private Branch $buea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Company::query()->update(['momo_number' => '670000000', 'phone' => '233000000', 'reconnection_fine' => '2000', 'relocation_fee' => '5000']);
        Company::forgetCache();

        $this->headquarters = Branch::query()->where('is_headquarters', true)->sole();
        $this->buea = BranchFactory::new()->create(['name' => 'Buea', 'momo_number' => '699111111', 'relocation_fee' => '3000']);
    }

    public function test_a_branch_inherits_headquarters_except_what_it_overrides(): void
    {
        $this->assertSame('670000000', Company::forBranch($this->headquarters->id)->momo_number);

        $buea = Company::forBranch($this->buea->id);
        $this->assertSame('699111111', $buea->momo_number);
        $this->assertSame('233000000', $buea->phone);
        $this->assertSame('670000000', Company::cached()->momo_number, 'The headquarters record itself is untouched.');
    }

    public function test_a_branch_view_of_the_company_cannot_be_saved(): void
    {
        $this->expectException(LogicException::class);

        Company::forBranch($this->buea->id)->save();
    }

    public function test_fees_and_documents_use_the_customers_branch(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->buea->id])->id, 'status' => 'active']);
        ManuscriptFactory::new()->forPeriod('2026-10')->create(['customer_id' => $customer->id]);

        $this->assertSame('3000.00', CustomerRelocationService::defaultFee(CustomerCharge::KIND_RELOCATION, $customer->branch_id));
        $this->assertSame('5000.00', CustomerRelocationService::defaultFee(CustomerCharge::KIND_RELOCATION, $this->headquarters->id));

        $this->assertSame('699111111', app(ManuscriptService::class)->billData($customer, '2026-10')['company']->momo_number);

        $receipt = app(PaymentReceiptService::class)->issueFor(PaymentFactory::new()->create(['customer_id' => $customer->id]), autoSend: false);
        $this->assertSame('699111111', $receipt->snapshot['company']['momo_number']);
    }

    public function test_a_branch_manager_sets_their_own_branch_only(): void
    {
        $this->as('manager', $this->buea->id);

        $this->get('/my-branch')->assertRedirect("/branches/{$this->buea->uuid}/settings");
        $this->get("/branches/{$this->buea->uuid}/settings")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Branches/Settings')
            ->where('branch.momo_number', '699111111')
            ->where('headquarters.momo_number', '670000000'));

        $this->patch("/branches/{$this->buea->uuid}/settings", ['momo_number' => '677222222', 'reconnection_fine' => '1500', 'phone' => ''])
            ->assertSessionHasNoErrors();

        $this->buea->refresh();
        $this->assertSame('677222222', $this->buea->momo_number);
        $this->assertSame('1500.00', (string) $this->buea->reconnection_fine);
        $this->assertNull($this->buea->phone);

        $limbe = BranchFactory::new()->create(['name' => 'Limbe']);
        $this->get("/branches/{$limbe->uuid}/settings")->assertForbidden();
        $this->patch("/branches/{$limbe->uuid}/settings", ['momo_number' => '1'])->assertForbidden();
        $this->get("/branches/{$this->headquarters->uuid}/settings")->assertForbidden();
    }

    public function test_whoever_manages_branches_sets_any_branch_but_not_headquarters(): void
    {
        $this->as('admin');

        $this->patch("/branches/{$this->buea->uuid}/settings", ['code' => 'BUE', 'reinstallation_fee' => '4000'])->assertSessionHasNoErrors();
        $this->assertSame('BUE', $this->buea->refresh()->code);

        $this->get("/branches/{$this->headquarters->uuid}/settings")->assertForbidden();
        $this->get('/my-branch')->assertNotFound();
    }

    public function test_managers_are_granted_their_branch_settings(): void
    {
        $managerRoleId = Role::query()->where('name', 'manager')->value('id');

        $this->assertTrue(DB::connection('tenant')->table('role_permissions')
            ->where('role_id', $managerRoleId)->where('permission', Permission::BranchesSettings->value)->exists());
    }

    private function as(string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }
}
