<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Zone;
use App\Services\CashBookService;
use App\Services\ZoneService;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ExpenseCategoryFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Branches v2 P2 — the fence (docs/plans/branches-v2.md §3, §5): a user fenced
 * to a branch only sees and acts on that branch; customer history stays whole
 * for its customer; company-wide limits and the billing run stay company-wide;
 * unfenced staff can narrow every screen with the branch switcher.
 */
class BranchFenceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $headquarters;

    private Branch $buea;

    private Customer $hqCustomer;

    private Customer $bueaCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();
        // An owner other than the requester, to approve bons above the limits (wave 4: nobody approves their own).
        TenantUser::query()->where('user_id', User::query()->where('email', 'divine@shalomtech.dev')->value('id'))->update(['role' => 'super']);

        $this->headquarters = Branch::query()->where('is_headquarters', true)->sole();
        $this->buea = BranchFactory::new()->create(['name' => 'Buea']);
        $this->hqCustomer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->headquarters->id])->id]);
        $this->bueaCustomer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->buea->id])->id]);
    }

    public function test_a_fenced_manager_sees_only_their_branch_customers_and_cannot_open_another_branchs(): void
    {
        $this->as('manager', $this->buea->id);

        $this->get('/customers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('customers.data', fn ($rows) => collect($rows)->pluck('uuid')->contains($this->bueaCustomer->uuid)
                && ! collect($rows)->pluck('uuid')->contains($this->hqCustomer->uuid)));

        $this->get("/customers/{$this->hqCustomer->uuid}")->assertNotFound();
        $this->get('/payments/'.PaymentFactory::new()->create(['customer_id' => $this->hqCustomer->id])->uuid)->assertNotFound();
    }

    public function test_a_fenced_manager_cannot_approve_another_branchs_bon(): void
    {
        $bon = $this->bon($this->headquarters, 'pending');
        $this->as('manager', $this->buea->id);

        $this->post("/vouchers/{$bon->uuid}/approve")->assertNotFound();
        $this->assertSame('pending', $bon->refresh()->status);
    }

    public function test_the_daily_bon_limit_counts_every_branch(): void
    {
        Company::query()->update(['voucher_approval_limit' => '100000', 'voucher_daily_limit' => '30000']);
        Company::forgetCache();
        $this->bon($this->headquarters, 'paid', '25000');

        $this->as('agent', $this->buea->id);
        $this->post('/vouchers', [
            'kind' => 'expense',
            'payee_name' => 'Total Buea',
            'purpose' => 'Fuel',
            'category_uuid' => ExpenseCategoryFactory::new()->create()->uuid,
            'amount' => '10000',
        ])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->withoutGlobalScope(BranchScope::class)->latest('id')->firstOrFail();
        $this->assertSame($this->buea->id, $voucher->branch_id);
        $this->assertSame(Voucher::TIER_STAKEHOLDERS, $voucher->approval_tier);
        $this->assertContains('above_daily_limit', $voucher->tier_reasons);
    }

    public function test_a_moved_customers_history_stays_whole_for_them_and_visible_where_it_happened(): void
    {
        $oldPayment = PaymentFactory::new()->create(['customer_id' => $this->hqCustomer->id]);
        ManuscriptFactory::new()->forPeriod('2026-09')->create(['customer_id' => $this->hqCustomer->id]);
        $this->hqCustomer->update(['zone_id' => Zone::query()->where('branch_id', $this->buea->id)->value('id')]);

        // The new branch bills from the customer's whole history.
        $this->fencedTo($this->buea->id);
        $this->assertNotNull(Customer::query()->findOrFail($this->hqCustomer->id)->latestManuscript);

        // The old branch still opens its own payment, with its customer.
        $this->fencedTo($this->headquarters->id);
        $this->assertSame($this->hqCustomer->id, Payment::query()->findOrFail($oldPayment->id)->customer?->id);
        $this->assertNull(Customer::query()->find($this->hqCustomer->id));
    }

    public function test_the_company_wallet_is_found_from_any_branch_and_never_duplicated(): void
    {
        $this->fencedTo($this->buea->id);
        $cashBook = app(CashBookService::class);

        $wallet = $cashBook->walletFor('mtn_momo');

        $this->assertSame($this->headquarters->id, $wallet->branch_id);
        $this->assertSame($wallet->id, $cashBook->walletFor('mtn_momo')->id);
        $this->assertSame(1, CashAccount::query()->withoutGlobalScope(BranchScope::class)
            ->where('kind', CashAccount::KIND_MOBILE_MONEY)->where('provider', 'mtn')->where('is_active', true)->count());
    }

    public function test_zone_dropdowns_show_only_the_fenced_branch(): void
    {
        $this->fencedTo($this->buea->id);

        $zones = app(ZoneService::class)->all();

        $this->assertNotEmpty($zones);
        $this->assertTrue($zones->every(fn (Zone $zone): bool => $zone->branch_id === $this->buea->id));
        $this->assertArrayNotHasKey('branch_id', $zones->first()->toArray());
    }

    public function test_only_staff_acting_for_the_company_may_run_the_billing(): void
    {
        $user = $this->as('admin', $this->buea->id);
        $this->fencedTo($this->buea->id, 'admin');
        $this->assertFalse(Gate::forUser($user)->allows('calculate', Manuscript::class));

        $this->fencedTo($this->headquarters->id, 'admin');
        $this->assertTrue(Gate::forUser($user)->allows('calculate', Manuscript::class));
    }

    public function test_the_switcher_narrows_every_screen_for_unfenced_staff_only(): void
    {
        $this->as('manager');

        $this->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->where('branchView.current', null)
            ->where('branchView.branches', fn ($branches) => count($branches) >= 2));

        $this->put('/branch-view', ['branch_uuid' => $this->buea->uuid])->assertRedirect();
        $this->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->where('branchView.current', $this->buea->uuid)
            ->where('customers.data', fn ($rows) => ! collect($rows)->pluck('uuid')->contains($this->hqCustomer->uuid)));

        $this->put('/branch-view', ['branch_uuid' => null])->assertRedirect();
        $this->get("/customers/{$this->hqCustomer->uuid}")->assertOk();

        // A fenced user's branch is fixed: no switcher, and the endpoint refuses.
        $this->as('manager', $this->buea->id);
        $this->get('/customers')->assertInertia(fn (Assert $page) => $page->where('branchView', null));
        $this->put('/branch-view', ['branch_uuid' => $this->headquarters->uuid])->assertForbidden();
    }

    private function as(string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }

    /** Serves the rest of the test as a user fenced to $branchId (as the middleware would). */
    private function fencedTo(int $branchId, string $role = 'manager'): void
    {
        $tenantUser = TenantUser::query()->where('tenant_id', 'swecom')->firstOrFail();

        app()->instance(TenantContext::class, new TenantContext($tenantUser, $role, $branchId));
    }

    private function bon(Branch $branch, string $status, string $amount = '5000'): Voucher
    {
        $requester = User::query()->where('email', 'terence@shalomtech.dev')->firstOrFail();

        return Voucher::query()->create([
            'number' => 'BC-FENCE-'.random_int(100000, 999999),
            'kind' => Voucher::KIND_EXPENSE,
            'payee_name' => 'Payee',
            'purpose' => 'Test bon',
            'expense_category_id' => ExpenseCategoryFactory::new()->create()->id,
            'amount' => $amount,
            'needed_on' => now()->toDateString(),
            'status' => $status,
            'approval_tier' => Voucher::TIER_STANDARD,
            'approval_mode' => 'any',
            'requested_by' => $requester->id,
            'branch_id' => $branch->id,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }
}
