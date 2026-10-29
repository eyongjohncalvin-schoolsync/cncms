<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Mail\VoucherApprovalMail;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CashBookService;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Cash controls wave 5 (docs/plans/cash-controls.md): vouchers / bons de
 * caisse — the company limits, every-investor approval above them (owner
 * fallback), maker ≠ checker ≠ payer, payment out of a till, the
 * Expenditure it writes, and cash advances justified afterwards.
 *
 * People (seeded swecom users; roles set per test): kelvin requests,
 * terence approves (manager), patience pays (cashier); godlove and blessing
 * are the investors when a test needs them.
 */
class VoucherTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();

        $this->category = ExpenseCategoryFactory::new()->create(['name' => 'Fuel']);
        TenantUser::query()->where('tenant_id', 'swecom')->update(['is_investor' => false]);
        // An owner other than the requester, to approve bons above the limits (wave 4: nobody approves their own).
        TenantUser::query()->where('user_id', User::query()->where('email', 'divine@shalomtech.dev')->value('id'))->update(['role' => 'super']);
        $this->limits('50000', null);
    }

    private function limits(?string $single, ?string $daily): void
    {
        Company::query()->update(['voucher_approval_limit' => $single, 'voucher_daily_limit' => $daily]);
        Cache::forget(Company::CACHE_KEY);
    }

    private function as(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function investors(string ...$emails): void
    {
        $ids = User::query()->whereIn('email', $emails)->pluck('id');
        TenantUser::query()->whereIn('user_id', $ids)->update(['is_investor' => true, 'role' => 'worker']);
    }

    private function request(string $amount, string $kind = 'expense', ?string $neededOn = null): Voucher
    {
        $this->as('kelvin@shalomtech.dev', 'agent');

        $this->post('/vouchers', array_filter([
            'kind' => $kind,
            'payee_name' => 'Total Kumba',
            'purpose' => 'Fuel for the generator',
            'category_uuid' => $this->category->uuid,
            'amount' => $amount,
            'needed_on' => $neededOn,
        ]))->assertSessionHasNoErrors();

        return Voucher::query()->latest('id')->firstOrFail();
    }

    private function fundCashierTill(string $amount): void
    {
        $cashier = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        app(CashBookService::class)->record(app(CashBookService::class)->tillFor($cashier), 'in', $amount, CashMovement::KIND_TRANSFER, $cashier, ['note' => 'Float']);
    }

    public function test_a_voucher_within_the_limit_needs_one_manager_then_the_cashier_pays_it(): void
    {
        $voucher = $this->request('20000');

        $this->assertSame(Voucher::TIER_STANDARD, $voucher->approval_tier);
        $this->assertStringStartsWith('BC-', $voucher->number);

        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $voucher->refresh()->status);

        $this->fundCashierTill('50000');
        $cashier = $this->as('patience@shalomtech.dev', 'cashier');
        $till = app(CashBookService::class)->tillFor($cashier);

        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till->uuid, 'method' => 'cash', 'received_by_name' => 'Total station attendant'])
            ->assertSessionHasNoErrors();

        $voucher->refresh();
        $this->assertSame('paid', $voucher->status);
        $this->assertSame('30000.00', app(CashBookService::class)->balance($till));
        $this->assertSame(CashMovement::KIND_VOUCHER_PAYOUT, CashMovement::query()->latest('id')->value('kind'));

        $expenditure = Expenditure::query()->findOrFail($voucher->expenditure_id);
        $this->assertSame('20000.00', (string) $expenditure->amount);
        $this->assertSame($this->category->id, $expenditure->category_id);

        $this->get("/vouchers/{$voucher->uuid}/pdf")->assertOk();
    }

    public function test_nobody_approves_or_pays_their_own_voucher(): void
    {
        $voucher = $this->request('20000');

        $this->as('kelvin@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertForbidden();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve");

        $this->fundCashierTill('50000');
        $this->as('kelvin@shalomtech.dev', 'cashier');
        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => 'x', 'method' => 'cash', 'received_by_name' => 'X'])->assertForbidden();
    }

    public function test_above_the_single_limit_every_investor_must_approve(): void
    {
        $this->investors('godlove@shalomtech.dev', 'blessing@shalomtech.dev');
        $voucher = $this->request('60000');

        $this->assertSame(Voucher::TIER_STAKEHOLDERS, $voucher->approval_tier);
        $this->assertSame(['above_single_limit'], $voucher->tier_reasons);
        $this->assertSame('all', $voucher->approval_mode);
        Mail::assertQueued(VoucherApprovalMail::class, 2);

        // A manager's approval doesn't count above the limit.
        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertForbidden();

        $this->as('godlove@shalomtech.dev', 'worker');
        $this->get('/vouchers')->assertOk()->assertInertia(fn (Assert $page) => $page->where('is_investor', true)
            ->where('vouchers', fn ($rows) => collect($rows)->firstWhere('uuid', $voucher->uuid)['awaiting_me'] === true));
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertSessionHasNoErrors();
        $this->assertSame('pending', $voucher->refresh()->status);

        // Nobody approves twice.
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertForbidden();

        $this->as('blessing@shalomtech.dev', 'worker');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $voucher->refresh()->status);
    }

    public function test_one_stakeholder_rejecting_rejects(): void
    {
        $this->investors('godlove@shalomtech.dev', 'blessing@shalomtech.dev');
        $voucher = $this->request('60000');

        $this->as('godlove@shalomtech.dev', 'worker');
        $this->post("/vouchers/{$voucher->uuid}/reject", ['reason' => 'Get a second quote'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $voucher->refresh()->status);
        $this->assertSame('Get a second quote', $voucher->rejection_reason);
    }

    public function test_without_investors_the_owner_approves_above_the_limit(): void
    {
        // The requester's role is switched by request(), so make the owner
        // someone else for this test.
        $owner = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $owner->id)->update(['role' => 'super']);

        $voucher = $this->request('60000');

        $this->assertSame('any', $voucher->approval_mode);
        $this->assertContains($owner->id, $voucher->requiredApproverIds());

        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertForbidden();

        $this->actingAs($owner);
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertSessionHasNoErrors();

        $this->assertSame('approved', $voucher->refresh()->status);
    }

    public function test_crossing_the_daily_limit_needs_the_stakeholders(): void
    {
        $this->limits(null, '30000');
        $first = $this->request('20000');
        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$first->uuid}/approve");

        $second = $this->request('20000');

        $this->assertSame(Voucher::TIER_STAKEHOLDERS, $second->approval_tier);
        $this->assertSame(['above_daily_limit'], $second->tier_reasons);
    }

    public function test_a_voucher_escalates_if_the_day_fills_up_before_it_is_approved(): void
    {
        $this->limits(null, '30000');
        $first = $this->request('20000');
        $second = $this->request('20000');
        $this->assertSame(Voucher::TIER_STANDARD, $second->approval_tier);

        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$first->uuid}/approve");
        $this->post("/vouchers/{$second->uuid}/approve")->assertSessionHasNoErrors();

        $second->refresh();
        $this->assertSame('pending', $second->status);
        $this->assertSame(Voucher::TIER_STAKEHOLDERS, $second->approval_tier);
    }

    public function test_a_payout_needs_the_money_to_be_there(): void
    {
        $voucher = $this->request('20000');
        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve");

        $cashier = $this->as('patience@shalomtech.dev', 'cashier');
        $till = app(CashBookService::class)->tillFor($cashier);

        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till->uuid, 'method' => 'cash', 'received_by_name' => 'X'])->assertSessionHasErrors('account');
        $this->assertSame('approved', $voucher->refresh()->status);
    }

    public function test_a_cash_advance_is_settled_with_receipts_and_change(): void
    {
        $voucher = $this->request('10000', Voucher::KIND_ADVANCE);
        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve");

        $this->fundCashierTill('10000');
        $cashier = $this->as('patience@shalomtech.dev', 'cashier');
        $till = app(CashBookService::class)->tillFor($cashier);
        $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till->uuid, 'method' => 'cash', 'received_by_name' => 'Kelvin']);

        $voucher->refresh();
        $this->assertSame('paid', $voucher->status);
        $this->assertNull($voucher->expenditure_id);
        $this->assertNotNull($voucher->settle_due_on);

        Storage::fake(config('filesystems.default', 'local'));
        $this->post("/vouchers/{$voucher->uuid}/settle", ['spent_amount' => 9000, 'returned_amount' => 2000])->assertSessionHasErrors('spent_amount');
        // Wave 4: what was spent is backed by a photo of the receipts.
        $this->post("/vouchers/{$voucher->uuid}/settle", ['spent_amount' => 7500, 'returned_amount' => 2500])->assertSessionHasErrors('receipt');
        $this->post("/vouchers/{$voucher->uuid}/settle", ['spent_amount' => 7500, 'returned_amount' => 2500, 'receipt' => UploadedFile::fake()->image('receipts.jpg')])->assertSessionHasNoErrors();

        $voucher->refresh();
        $this->assertSame('settled', $voucher->status);
        $this->assertSame('7500.00', (string) Expenditure::query()->findOrFail($voucher->expenditure_id)->amount);
        $this->assertSame('2500.00', app(CashBookService::class)->balance($till));
    }

    public function test_the_requester_can_cancel_while_pending(): void
    {
        $voucher = $this->request('20000');

        $this->post("/vouchers/{$voucher->uuid}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $voucher->refresh()->status);
    }

    public function test_an_expense_needs_a_category_and_workers_cannot_request(): void
    {
        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->post('/vouchers', ['kind' => 'expense', 'payee_name' => 'X', 'purpose' => 'Y', 'amount' => 1000])->assertSessionHasErrors('category_uuid');

        $this->as('kelvin@shalomtech.dev', 'worker');
        $this->post('/vouchers', ['kind' => 'advance', 'payee_name' => 'X', 'purpose' => 'Y', 'amount' => 1000])->assertForbidden();
        $this->get('/vouchers')->assertForbidden();
    }
}
