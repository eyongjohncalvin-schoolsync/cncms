<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\Company;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CashAlertService;
use App\Services\CashBookService;
use App\Services\SalaryService;
use App\Services\VoucherService;
use App\Support\TenantContext;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Money must balance at every level, wave 4 — bons (owner, 2026-10-28/29):
 * nobody approves, pays or settles their own bon, the owner included; a
 * refund or return bon is only paid, never rejected; an advance is settled
 * against a photo of its receipts and what is missing is owned; a salary is
 * paid only through its own bon, once per person and month.
 */
class BonControlsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private string $categoryUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();
        Storage::fake(config('filesystems.default', 'local'));
        TenantUser::query()->where('tenant_id', 'swecom')->update(['is_investor' => false]);
        Company::query()->update(['voucher_approval_limit' => '200000', 'voucher_daily_limit' => null, 'monthly_spending_cap' => null]);
        Cache::forget(Company::CACHE_KEY);
        $this->categoryUuid = ExpenseCategoryFactory::new()->create(['name' => 'Fuel '.uniqid()])->uuid;
    }

    private function as(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function bon(string $amount = '20000', string $kind = Voucher::KIND_EXPENSE): Voucher
    {
        $this->post('/vouchers', ['kind' => $kind, 'payee_name' => 'Total Kumba', 'purpose' => 'Fuel', 'category_uuid' => $this->categoryUuid, 'amount' => $amount])->assertSessionHasNoErrors();

        return Voucher::query()->latest('id')->firstOrFail();
    }

    private function fundTill(User $holder, string $amount): string
    {
        $till = app(CashBookService::class)->tillFor($holder);
        app(CashBookService::class)->record($till, 'in', $amount, CashMovement::KIND_TRANSFER, $holder, ['note' => 'Float']);

        return $till->uuid;
    }

    public function test_the_owner_never_approves_or_pays_their_own_bon(): void
    {
        $owner = $this->as('kelvin@shalomtech.dev', 'super');
        $voucher = $this->bon();

        $this->post("/vouchers/{$voucher->uuid}/approve")->assertForbidden();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $voucher->refresh()->status);

        $this->actingAs($owner);
        app()->forgetInstance(TenantContext::class);
        $till = $this->fundTill($owner, '50000');
        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till, 'method' => 'cash', 'received_by_name' => 'Total'])->assertForbidden();
        $this->assertSame('approved', $voucher->refresh()->status);
    }

    public function test_an_owner_alone_cannot_raise_a_bon_above_the_limits(): void
    {
        Company::query()->update(['voucher_approval_limit' => '10000']);
        Cache::forget(Company::CACHE_KEY);
        TenantUser::query()->where('tenant_id', 'swecom')->where('role', 'super')->update(['role' => 'admin']);

        $this->as('kelvin@shalomtech.dev', 'super');
        $this->post('/vouchers', ['kind' => 'expense', 'payee_name' => 'Total', 'purpose' => 'Fuel', 'category_uuid' => $this->categoryUuid, 'amount' => '50000'])
            ->assertSessionHasErrors('amount');
    }

    public function test_a_refund_bon_is_only_paid_never_rejected_or_cancelled_and_needs_the_signed_copy(): void
    {
        $requester = $this->as('kelvin@shalomtech.dev', 'manager');
        $voucher = app(VoucherService::class)->request([
            'kind' => Voucher::KIND_EXPENSE, 'payee_name' => 'A customer', 'purpose' => 'Refund — payment reversed',
            'category_uuid' => $this->categoryUuid, 'amount' => '5000', 'origin' => Voucher::ORIGIN_REFUND,
        ], $requester);

        $this->post("/vouchers/{$voucher->uuid}/cancel")->assertForbidden();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/reject", ['reason' => 'No'])->assertSessionHasErrors('reason');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertSessionHasNoErrors();

        $cashier = $this->as('patience@shalomtech.dev', 'cashier');
        $till = $this->fundTill($cashier, '10000');
        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till, 'method' => 'cash', 'received_by_name' => 'A customer'])->assertSessionHasErrors('signed_copy');
        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till, 'method' => 'cash', 'received_by_name' => 'A customer', 'signed_copy' => UploadedFile::fake()->image('signed.jpg')])
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $voucher->refresh()->status);

        // Evidence is attached once and never swapped.
        $this->post("/vouchers/{$voucher->uuid}/signed-copy", ['signed_copy' => UploadedFile::fake()->image('other.jpg')])->assertSessionHasErrors('signed_copy');
    }

    public function test_a_refund_bon_left_unpaid_is_on_the_alerts(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 09:00', 'Africa/Lagos'));
        $requester = $this->as('kelvin@shalomtech.dev', 'manager');
        app(VoucherService::class)->request([
            'kind' => Voucher::KIND_EXPENSE, 'payee_name' => 'A customer', 'purpose' => 'Refund',
            'category_uuid' => $this->categoryUuid, 'amount' => '5000', 'origin' => Voucher::ORIGIN_REFUND,
        ], $requester);

        $this->travelTo(Carbon::parse('2026-11-04 09:00', 'Africa/Lagos'));
        $this->assertContains('refund_bons_waiting', array_column(app(CashAlertService::class)->alerts(), 'key'));
    }

    public function test_an_advance_not_accounted_for_is_the_requesters_shortage(): void
    {
        $requester = $this->as('kelvin@shalomtech.dev', 'agent');
        $voucher = $this->bon('10000', Voucher::KIND_ADVANCE);

        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve");

        $cashier = $this->as('patience@shalomtech.dev', 'cashier');
        $till = $this->fundTill($cashier, '10000');
        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till, 'method' => 'cash', 'received_by_name' => 'Kelvin'])->assertSessionHasNoErrors();

        $this->post("/vouchers/{$voucher->uuid}/settle", ['spent_amount' => 6000, 'returned_amount' => 1000, 'receipt' => UploadedFile::fake()->image('receipts.jpg')])
            ->assertSessionHasNoErrors();

        $voucher->refresh();
        $this->assertSame('settled', $voucher->status);
        $this->assertNotNull($voucher->receipt_path);
        $shortage = CashShortage::query()->open()->where('kind', CashShortage::KIND_ADVANCE)->sole();
        $this->assertSame([$requester->id, '3000.00'], [$shortage->owner_user_id, (string) $shortage->amount]);
    }

    /** An approved, paid cash advance requested by kelvin, paid by patience from her till. */
    private function paidAdvance(string $amount = '10000', array $extra = []): Voucher
    {
        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->post('/vouchers', ['kind' => Voucher::KIND_ADVANCE, 'payee_name' => 'Kelvin', 'purpose' => 'Fuel', 'category_uuid' => $this->categoryUuid, 'amount' => $amount, ...$extra])
            ->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();

        $this->as('godlove@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertSessionHasNoErrors();

        $cashier = $this->as('patience@shalomtech.dev', 'cashier');
        $till = $this->fundTill($cashier, $amount);
        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till, 'method' => 'cash', 'received_by_name' => 'Kelvin'])->assertSessionHasNoErrors();

        return $voucher->refresh();
    }

    public function test_an_advance_past_its_date_becomes_the_requesters_shortage_and_late_receipts_still_settle_it(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 09:00', 'Africa/Lagos'));
        $voucher = $this->paidAdvance();

        $this->travelTo(Carbon::parse($voucher->settle_due_on->toDateString().' 09:00', 'Africa/Lagos')->addDay());
        $this->artisan('vouchers:advances-overdue', ['tenant' => 'swecom'])->assertSuccessful();
        $this->artisan('vouchers:advances-overdue', ['tenant' => 'swecom'])->assertSuccessful();

        $overdue = CashShortage::query()->where('source_id', $voucher->id)->where('kind', CashShortage::KIND_ADVANCE)->sole();
        $this->assertSame([CashShortage::STATUS_OPEN, '10000.00', $this->user('kelvin@shalomtech.dev')->id], [$overdue->status, (string) $overdue->amount, $overdue->owner_user_id]);
        $this->assertNotContains('advances_overdue', array_column(app(CashAlertService::class)->alerts(), 'key'));

        // Receipts for 9 000, late: the overdue shortage gives way to the real 1 000 gap.
        $this->post("/vouchers/{$voucher->uuid}/settle", ['spent_amount' => 9000, 'returned_amount' => 0, 'receipt' => UploadedFile::fake()->image('receipts.jpg')])
            ->assertSessionHasNoErrors();

        $this->assertSame(CashShortage::STATUS_JUSTIFIED, $overdue->refresh()->status);
        $this->assertSame('1000.00', (string) CashShortage::query()->open()->where('source_id', $voucher->id)->sole()->amount);
        $this->assertSame('settled', $voucher->refresh()->status);
    }

    public function test_settling_an_overdue_advances_shortage_closes_the_advance(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 09:00', 'Africa/Lagos'));
        $voucher = $this->paidAdvance();
        $this->travelTo(Carbon::parse($voucher->settle_due_on->toDateString().' 09:00', 'Africa/Lagos')->addDay());
        $this->artisan('vouchers:advances-overdue', ['tenant' => 'swecom'])->assertSuccessful();
        $shortage = CashShortage::query()->open()->where('source_id', $voucher->id)->sole();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/cash/shortages/{$shortage->uuid}/settle", ['outcome' => 'written_off', 'note' => 'Lost the receipts'])->assertSessionHasNoErrors();

        $voucher->refresh();
        $this->assertSame('settled', $voucher->status);
        $this->assertStringContainsString('written off: Lost the receipts', (string) $voucher->settlement_note);
    }

    public function test_a_bon_paid_to_staff_is_never_approved_by_them_and_their_advance_is_theirs(): void
    {
        $terence = $this->user('terence@shalomtech.dev');
        $voucher = $this->paidAdvance('10000', ['payee_user_uuid' => $terence->uuid]);

        $this->assertSame([$terence->id, $terence->name], [$voucher->payee_user_id, $voucher->payee_name]);

        // The payee never approves a bon that pays them.
        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->post('/vouchers', ['kind' => Voucher::KIND_EXPENSE, 'payee_user_uuid' => $terence->uuid, 'purpose' => 'Transport', 'category_uuid' => $this->categoryUuid, 'amount' => '2000'])->assertSessionHasNoErrors();
        $second = Voucher::query()->latest('id')->firstOrFail();
        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$second->uuid}/approve")->assertForbidden();

        // The advance not accounted for is the payee's, not the requester's.
        $this->as('patience@shalomtech.dev', 'cashier');
        $this->post("/vouchers/{$voucher->uuid}/settle", ['spent_amount' => 0, 'returned_amount' => 4000])->assertSessionHasNoErrors();
        $this->assertSame($terence->id, CashShortage::query()->open()->where('source_id', $voucher->id)->sole()->owner_user_id);

        // Someone outside: a typed name is still needed.
        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->post('/vouchers', ['kind' => Voucher::KIND_EXPENSE, 'purpose' => 'X', 'category_uuid' => $this->categoryUuid, 'amount' => '1000'])->assertSessionHasErrors('payee_name');
    }

    public function test_a_salary_can_be_paid_in_parts_never_above_the_approved_salary(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 09:00', 'Africa/Lagos'));
        $admin = $this->as('kelvin@shalomtech.dev', 'admin');
        $staff = $this->user('terence@shalomtech.dev');
        app(SalaryService::class)->set($staff->id, null, '75000', null, null, $admin);
        $tenantUser = TenantUser::query()->where('user_id', $staff->id)->sole();

        $this->post("/users/{$tenantUser->id}/salary/pay", ['period' => '2026-11', 'amount' => 30000])->assertSessionHasNoErrors();
        $this->post("/users/{$tenantUser->id}/salary/pay", ['period' => '2026-11', 'amount' => 50000])->assertSessionHasErrors('amount');
        $this->post("/users/{$tenantUser->id}/salary/pay", ['period' => '2026-11'])->assertSessionHasNoErrors();
        $this->post("/users/{$tenantUser->id}/salary/pay", ['period' => '2026-11', 'amount' => 1000])->assertSessionHasErrors('period');

        $this->assertSame(['30000.00', '45000.00'], Voucher::query()->where('salary_user_id', $staff->id)->orderBy('id')->pluck('amount')->map(fn ($amount): string => (string) $amount)->all());
        $this->assertStringStartsWith('Salary (part)', Voucher::query()->where('salary_user_id', $staff->id)->orderBy('id')->value('purpose'));
    }

    public function test_a_salary_is_paid_once_per_month_at_the_approved_salary_never_by_the_person_paid(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 09:00', 'Africa/Lagos'));
        $admin = $this->as('kelvin@shalomtech.dev', 'admin');
        $staff = $this->user('terence@shalomtech.dev');
        app(SalaryService::class)->set($staff->id, null, '75000', null, null, $admin);
        $tenantUser = TenantUser::query()->where('user_id', $staff->id)->sole();

        $this->post("/users/{$tenantUser->id}/salary/pay", ['period' => '2026-12'])->assertSessionHasErrors('period');
        $this->post("/users/{$tenantUser->id}/salary/pay", ['period' => '2026-11'])->assertSessionHasNoErrors();
        $this->post("/users/{$tenantUser->id}/salary/pay", ['period' => '2026-11'])->assertSessionHasErrors('period');

        $voucher = Voucher::query()->where('salary_user_id', $staff->id)->sole();
        $this->assertSame([Voucher::ORIGIN_SALARY, '2026-11', '75000.00'], [$voucher->origin, $voucher->salary_period, (string) $voucher->amount]);

        // The person paid never approves their own salary bon.
        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertForbidden();
    }
}
