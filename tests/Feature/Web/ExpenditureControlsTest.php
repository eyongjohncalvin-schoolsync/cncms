<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\ExpenditureMonth;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CashAlertService;
use App\Services\CashBookService;
use App\Services\SpendingControlService;
use App\Support\BusinessTimezone;
use Database\Factories\CustomerFactory;
use Database\Factories\ExpenditureFactory;
use Database\Factories\ExpenseCategoryFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * docs/plans/expenditures-and-spending-controls.md — one cash-out point
 * (bons), the daily limit counted by pay day, the monthly cap, budgets,
 * the deficit warning, the expenditures month view, corrections and voids,
 * the month close, and Cameroon time.
 *
 * People (seeded swecom users; roles set per test): kelvin requests (agent),
 * terence approves (manager), patience pays (cashier), godlove acts as
 * admin, blessing as the owner.
 */
class ExpenditureControlsTest extends TestCase
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
        // Nothing else spent this month gets in the way of the figures below.
        Expenditure::query()->update(['status' => 'void']);
        Voucher::query()->whereIn('status', ['approved', 'paid'])->update(['status' => 'cancelled']);
        $this->limits(single: '200000', daily: '100000', cap: null);
    }

    private function limits(?string $single, ?string $daily, ?string $cap): void
    {
        Company::query()->update(['voucher_approval_limit' => $single, 'voucher_daily_limit' => $daily, 'monthly_spending_cap' => $cap]);
        Company::forgetCache();
    }

    private function as(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function request(string $amount, array $extra = []): Voucher
    {
        $this->as('kelvin@shalomtech.dev', 'agent');

        $this->post('/vouchers', [
            'kind' => 'expense',
            'payee_name' => 'Total Kumba',
            'purpose' => 'Fuel for the generator',
            'category_uuid' => $this->category->uuid,
            'amount' => $amount,
            ...$extra,
        ])->assertSessionHasNoErrors();

        return Voucher::query()->latest('id')->firstOrFail();
    }

    private function approve(Voucher $voucher): void
    {
        $this->as('terence@shalomtech.dev', 'manager');
        $this->post("/vouchers/{$voucher->uuid}/approve")->assertSessionHasNoErrors();
    }

    private function pay(Voucher $voucher, bool $expectOk = true): void
    {
        $cashier = $this->as('patience@shalomtech.dev', 'cashier');
        $till = app(CashBookService::class)->tillFor($cashier);

        if (bccomp(app(CashBookService::class)->balance($till), (string) $voucher->amount, 2) < 0) {
            app(CashBookService::class)->record($till, 'in', '500000', CashMovement::KIND_OPENING_BALANCE, $cashier);
        }

        $response = $this->post("/vouchers/{$voucher->uuid}/pay", ['account' => $till->uuid, 'method' => 'cash', 'received_by_name' => 'Total station attendant']);

        $expectOk ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('account');
    }

    private function paidBon(string $amount): Voucher
    {
        $voucher = $this->request($amount);
        $this->approve($voucher);
        $this->pay($voucher);

        return $voucher->refresh();
    }

    private function legacyExpenditure(string $amount, ?string $spentAt = null, ?int $categoryId = null): Expenditure
    {
        return ExpenditureFactory::new()->create([
            'category_id' => $categoryId ?? $this->category->id,
            'user_id' => User::query()->where('email', 'terence@shalomtech.dev')->value('id'),
            'amount' => $amount,
            'spent_at' => $spentAt ?? BusinessTimezone::today(),
        ])->refresh();
    }

    // ---- E1: one cash-out point -------------------------------------

    public function test_paying_a_bon_writes_the_only_expenditure_linked_to_it_with_the_payee(): void
    {
        $voucher = $this->paidBon('20000');

        $expenditure = Expenditure::query()->findOrFail($voucher->expenditure_id);
        $this->assertSame(Expenditure::SOURCE_VOUCHER, $expenditure->source);
        $this->assertSame($voucher->id, $expenditure->voucher_id);
        $this->assertSame(BusinessTimezone::today(), $expenditure->spent_at->toDateString());
        // The payee is no longer blank in the note.
        $this->assertStringContainsString('Paid to Total station attendant', $expenditure->notes);

        // The bon's expenditure can't be deleted out from under it.
        $this->expectException(QueryException::class);
        DB::connection('tenant')->transaction(fn () => DB::connection('tenant')->table('expenditures')->where('id', $expenditure->id)->delete());
    }

    public function test_an_advance_needs_its_category_at_settlement_so_it_reaches_the_pnl(): void
    {
        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->post('/vouchers', ['kind' => 'advance', 'payee_name' => 'Kelvin', 'purpose' => 'Field trip', 'amount' => '10000'])->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->assertNull($voucher->expense_category_id);

        $this->approve($voucher);
        $this->pay($voucher);

        $this->post("/vouchers/{$voucher->uuid}/settle", ['spent_amount' => 8000, 'returned_amount' => 2000])->assertSessionHasErrors('category_uuid');
        $this->post("/vouchers/{$voucher->uuid}/settle", ['spent_amount' => 8000, 'returned_amount' => 2000, 'category_uuid' => $this->category->uuid])->assertSessionHasNoErrors();

        $voucher->refresh();
        $this->assertSame('settled', $voucher->status);
        $this->assertSame($this->category->id, Expenditure::query()->findOrFail($voucher->expenditure_id)->category_id);
    }

    // ---- E8: daily limit by pay day ---------------------------------

    public function test_the_daily_limit_is_on_by_default_at_100000(): void
    {
        $default = DB::connection('tenant')->selectOne("select column_default from information_schema.columns where table_schema = current_schema() and table_name = 'companies' and column_name = 'voucher_daily_limit'");

        $this->assertStringContainsString('100000', (string) $default->column_default);
    }

    public function test_future_dating_a_bon_no_longer_dodges_the_daily_limit(): void
    {
        $this->paidBon('60000');

        // Requested for next week: the old rule counted that day, not the pay day.
        $second = $this->request('60000', ['needed_on' => BusinessTimezone::now()->addWeek()->toDateString()]);

        $this->assertSame(Voucher::TIER_STAKEHOLDERS, $second->approval_tier);
        $this->assertSame(['above_daily_limit'], $second->tier_reasons);
    }

    public function test_the_limit_is_rechecked_at_pay_with_the_pay_day(): void
    {
        $this->limits('200000', '200000', null);
        $first = $this->request('60000');
        $second = $this->request('60000');
        $this->approve($first);
        $this->approve($second);

        $this->limits('200000', '100000', null);
        $this->pay($first);
        $this->pay($second, expectOk: false);

        $second->refresh();
        $this->assertSame('pending', $second->status);
        $this->assertSame(Voucher::TIER_STAKEHOLDERS, $second->approval_tier);
        $this->assertContains('above_daily_limit', $second->tier_reasons);
        $this->assertNull($second->expenditure_id);
    }

    public function test_limit_checks_run_under_one_lock_at_approve_and_pay(): void
    {
        $voucher = $this->request('10000');

        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $locks[] = $query->bindings[0] ?? null;
            }
        });

        $this->approve($voucher);
        $this->pay($voucher);

        $this->assertCount(2, $locks);
        $this->assertSame(['voucher-limits:swecom', 'voucher-limits:swecom'], $locks);
    }

    // ---- E2–E4: monthly cap -----------------------------------------

    public function test_a_bon_over_the_monthly_cap_needs_every_stakeholder(): void
    {
        $this->limits('200000', null, '100000');
        $this->legacyExpenditure('90000');

        $voucher = $this->request('20000');

        $this->assertSame(Voucher::TIER_STAKEHOLDERS, $voucher->approval_tier);
        $this->assertSame(['above_monthly_cap'], $voucher->tier_reasons);
    }

    public function test_approved_but_unpaid_bons_count_against_the_cap_and_it_is_rechecked_at_pay(): void
    {
        $this->limits('200000', null, '200000');
        $first = $this->request('60000');
        $this->approve($first);
        $second = $this->request('50000');
        $this->assertSame(Voucher::TIER_STANDARD, $second->approval_tier);
        $this->approve($second);

        $this->assertSame('110000.00', app(SpendingControlService::class)->committedSpend(BusinessTimezone::currentPeriod()));

        // The owner lowers the cap; the payout is re-checked against it.
        $this->limits('200000', null, '100000');
        $this->pay($second, expectOk: false);

        $second->refresh();
        $this->assertSame('pending', $second->status);
        $this->assertContains('above_monthly_cap', $second->tier_reasons);
    }

    public function test_the_bon_form_shows_the_cap_and_budgets(): void
    {
        $this->limits('200000', null, '500000');
        Budget::query()->create(['category_id' => $this->category->id, 'period' => BusinessTimezone::currentPeriod(), 'amount' => 10000]);
        $this->legacyExpenditure('9000');

        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->get('/vouchers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('spending.cap', '500000.00')
            ->where('spending.spent', '9000.00')
            ->where('spending.categories', fn ($rows) => collect($rows)->firstWhere('uuid', $this->category->uuid)['level'] === 'amber'));
    }

    // ---- E5: budgets ------------------------------------------------

    public function test_budgets_warn_by_category_id_and_never_block(): void
    {
        $twin = ExpenseCategoryFactory::new()->create(['name' => 'Fuel']);
        $period = BusinessTimezone::currentPeriod();
        Budget::query()->create(['category_id' => $this->category->id, 'period' => $period, 'amount' => 10000]);
        Budget::query()->create(['category_id' => $twin->id, 'period' => $period, 'amount' => 10000]);
        $this->legacyExpenditure('10000');

        $rows = collect(app(SpendingControlService::class)->summary($period)['categories'])->keyBy('uuid');
        $this->assertSame('red', $rows[$this->category->uuid]['level']);
        // Same name, different category: its budget is untouched.
        $this->assertSame('0.00', $rows[$twin->uuid]['amount']);

        // A warning only — the bon still goes to the normal approver.
        $voucher = $this->request('50000');
        $this->assertSame(Voucher::TIER_STANDARD, $voucher->approval_tier);
    }

    public function test_budgets_page_saves_copies_and_is_gated(): void
    {
        $period = BusinessTimezone::currentPeriod();
        $previous = BusinessTimezone::previousPeriod();
        $other = ExpenseCategoryFactory::new()->create();
        Budget::query()->create(['category_id' => $other->id, 'period' => $previous, 'amount' => 7000]);

        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->get('/resources/budgets')->assertForbidden();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/resources/budgets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Resources/Budgets')->where('grid.month', $period));

        $this->put('/resources/budgets', ['month' => $period, 'amounts' => [$this->category->uuid => '15000']])->assertSessionHasNoErrors();
        $this->assertSame('15000.00', (string) Budget::query()->where('period', $period)->where('category_id', $this->category->id)->value('amount'));

        $this->post('/resources/budgets/copy', ['month' => $period])->assertSessionHasNoErrors();
        $this->assertSame('7000.00', (string) Budget::query()->where('period', $period)->where('category_id', $other->id)->value('amount'));

        // Blank removes a budget.
        $this->put('/resources/budgets', ['month' => $period, 'amounts' => [$this->category->uuid => null]])->assertSessionHasNoErrors();
        $this->assertFalse(Budget::query()->where('period', $period)->where('category_id', $this->category->id)->exists());
    }

    // ---- Deficit ----------------------------------------------------

    public function test_a_month_spending_more_than_its_income_is_flagged_but_technician_fees_are_not_income(): void
    {
        $period = BusinessTimezone::currentPeriod();
        $before = app(SpendingControlService::class)->verifiedIncome($period);
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id]);

        Payment::query()->create(['customer_id' => $customer->id, 'amount' => 100000000, 'frequency' => 'monthly', 'verification_status' => 'verified', 'purpose' => Payment::PURPOSE_TECHNICIAN_FEE]);
        $this->assertSame($before, app(SpendingControlService::class)->verifiedIncome($period));

        $this->legacyExpenditure(bcadd($before, '1000', 2));

        $summary = app(SpendingControlService::class)->summary($period);
        $this->assertTrue($summary['deficit']);
        $this->assertContains('month_deficit', array_column(app(CashAlertService::class)->alerts(), 'key'));

        $this->as('blessing@shalomtech.dev', 'super');
        $this->get('/resources/expenditures')->assertOk()->assertInertia(fn (Assert $page) => $page->where('summary.deficit', true));
        $this->get('/resources')->assertOk()->assertInertia(fn (Assert $page) => $page->where('spending.deficit', true));
    }

    // ---- Index / detail ---------------------------------------------

    public function test_the_index_shows_the_current_month_with_filters_and_links_to_the_bon(): void
    {
        $voucher = $this->paidBon('20000');
        $old = $this->legacyExpenditure('3000', BusinessTimezone::periodStart(BusinessTimezone::previousPeriod())->toDateString());

        $this->as('blessing@shalomtech.dev', 'super');

        $this->get('/resources/expenditures')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Resources/Expenditures/Index')
            ->where('month', BusinessTimezone::currentPeriod())
            ->where('expenditures.data', fn ($rows) => collect($rows)->contains(fn ($row) => ($row['voucher']['number'] ?? null) === $voucher->number)
                && ! collect($rows)->contains('uuid', $old->uuid)));

        $this->get('/resources/expenditures?month='.BusinessTimezone::previousPeriod().'&source=legacy')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('expenditures.data', fn ($rows) => collect($rows)->contains('uuid', $old->uuid) && collect($rows)->every(fn ($row) => $row['source'] === 'legacy')));

        $this->get('/resources/expenditures/export')->assertOk()->assertDownload('expenditures-'.BusinessTimezone::currentPeriod().'.xlsx');
    }

    public function test_admin_recategorises_with_a_reason_but_never_the_requester_and_history_shows_it(): void
    {
        $voucher = $this->paidBon('20000');
        $expenditure = Expenditure::query()->findOrFail($voucher->expenditure_id);
        $other = ExpenseCategoryFactory::new()->create(['name' => 'Transport']);

        // kelvin requested the bon: even as admin he can't change it.
        $this->as('kelvin@shalomtech.dev', 'admin');
        $this->patch("/resources/expenditures/{$expenditure->uuid}", ['category_uuid' => $other->uuid, 'reason' => 'x'])->assertForbidden();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->patch("/resources/expenditures/{$expenditure->uuid}", ['category_uuid' => $other->uuid, 'reason' => 'x'])->assertForbidden();

        $this->as('godlove@shalomtech.dev', 'admin');
        $this->patch("/resources/expenditures/{$expenditure->uuid}", ['category_uuid' => $other->uuid])->assertSessionHasErrors('reason');
        $this->patch("/resources/expenditures/{$expenditure->uuid}", ['category_uuid' => $other->uuid, 'notes' => '', 'reason' => 'It was a taxi'])->assertSessionHasNoErrors();

        $expenditure->refresh();
        $this->assertSame($other->id, $expenditure->category_id);
        $this->assertNull($expenditure->notes);
        $this->assertSame('20000.00', (string) $expenditure->amount);

        $this->get("/resources/expenditures/{$expenditure->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Resources/Expenditures/Show')
            ->where('voucher.number', $voucher->number)
            ->where('history', fn ($history) => collect($history)->contains(fn ($entry) => $entry['reason'] === 'It was a taxi'
                && collect($entry['changes'])->contains(fn ($change) => $change['field'] === 'Category' && $change['to'] === 'Transport'))));

        // A bon's expenditure is corrected on the bon, never voided.
        $this->post("/resources/expenditures/{$expenditure->uuid}/void", ['reason' => 'x'])->assertForbidden();
    }

    // ---- Corrections ------------------------------------------------

    public function test_money_returned_on_a_paid_bon_goes_back_to_the_till_and_reduces_this_month(): void
    {
        $voucher = $this->paidBon('20000');

        $this->as('kelvin@shalomtech.dev', 'admin');
        $this->post("/vouchers/{$voucher->uuid}/correct", ['outcome' => 'returned', 'amount' => 5000, 'reason' => 'x'])->assertForbidden();

        $admin = $this->as('godlove@shalomtech.dev', 'admin');
        $till = app(CashBookService::class)->tillFor($admin);
        $this->post("/vouchers/{$voucher->uuid}/correct", ['outcome' => 'returned', 'amount' => 25000, 'reason' => 'Too much'])->assertSessionHasErrors('amount');
        $this->post("/vouchers/{$voucher->uuid}/correct", ['outcome' => 'returned', 'amount' => 5000, 'reason' => 'Price was lower'])->assertSessionHasNoErrors();

        $this->assertSame('5000.00', app(CashBookService::class)->balance($till));
        $this->assertSame(CashMovement::KIND_BON_CORRECTION, CashMovement::query()->latest('id')->value('kind'));

        $adjustment = Expenditure::query()->where('voucher_id', $voucher->id)->where('source', Expenditure::SOURCE_ADJUSTMENT)->sole();
        $this->assertSame('-5000.00', (string) $adjustment->amount);
        $this->assertSame($voucher->expenditure_id, $adjustment->corrects_expenditure_id);
        $this->assertSame('15000.00', app(SpendingControlService::class)->spentInPeriod(BusinessTimezone::currentPeriod()));
    }

    public function test_an_underpaid_bon_is_corrected_with_a_new_bon(): void
    {
        $voucher = $this->paidBon('20000');

        $this->as('godlove@shalomtech.dev', 'admin');
        $this->post("/vouchers/{$voucher->uuid}/correct", ['outcome' => 'underpaid', 'amount' => 3000, 'reason' => 'Fuel price rose'])->assertSessionHasNoErrors();

        $topUp = Voucher::query()->latest('id')->firstOrFail();
        $this->assertNotSame($voucher->id, $topUp->id);
        $this->assertSame('pending', $topUp->status);
        $this->assertSame('3000.00', (string) $topUp->amount);
        $this->assertStringContainsString($voucher->number, $topUp->purpose);
    }

    // ---- E6: month close --------------------------------------------

    public function test_the_previous_month_closes_on_the_fifth_and_only_the_owner_reopens_it(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00', BusinessTimezone::WAT));
        $october = $this->legacyExpenditure('4000', '2026-10-15');
        ExpenditureMonth::query()->where('period', '2026-10')->delete();

        Artisan::call('expenditures:close-months', ['tenant' => 'swecom']);
        $this->assertFalse(ExpenditureMonth::query()->where('period', '2026-10')->exists());

        $this->travelTo(Carbon::parse('2026-11-05 00:30', BusinessTimezone::WAT));
        Artisan::call('expenditures:close-months', ['tenant' => 'swecom']);

        $month = ExpenditureMonth::query()->where('period', '2026-10')->sole();
        $this->assertTrue($month->isClosed());
        $this->assertNotNull($month->snapshot['spent'] ?? null);

        // Closed: nobody re-categorises or voids in it.
        $this->as('godlove@shalomtech.dev', 'admin');
        $this->post("/resources/expenditures/{$october->uuid}/void", ['reason' => 'Duplicate'])->assertSessionHasErrors('reason');
        $this->post('/resources/expenditure-months/2026-10/reopen', ['reason' => 'Fix'])->assertForbidden();

        $this->as('blessing@shalomtech.dev', 'super');
        $this->post('/resources/expenditure-months/2026-10/reopen', [])->assertSessionHasErrors('reason');
        $this->post('/resources/expenditure-months/2026-10/reopen', ['reason' => 'Duplicate found'])->assertSessionHasNoErrors();
        $this->assertFalse($month->refresh()->isClosed());
        $this->assertTrue(AuditLog::query()->where('table_name', 'expenditure_months')->where('record_id', $month->id)->where('action', 'update')->exists());

        // The daily job doesn't close a reopened month behind the owner's back.
        Artisan::call('expenditures:close-months', ['tenant' => 'swecom']);
        $this->assertFalse($month->refresh()->isClosed());

        $this->as('godlove@shalomtech.dev', 'admin');
        $this->post("/resources/expenditures/{$october->uuid}/void", ['reason' => 'Duplicate'])->assertSessionHasNoErrors();
        $this->assertTrue($october->refresh()->isVoid());
    }

    public function test_a_late_settlement_lands_in_the_current_month_pointing_back(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 10:00', BusinessTimezone::WAT));
        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->post('/vouchers', ['kind' => 'advance', 'payee_name' => 'Kelvin', 'purpose' => 'Trip', 'category_uuid' => $this->category->uuid, 'amount' => '10000'])->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->approve($voucher);
        $this->pay($voucher);

        $this->travelTo(Carbon::parse('2026-11-02 10:00', BusinessTimezone::WAT));
        $this->post("/vouchers/{$voucher->uuid}/settle", ['spent_amount' => 10000, 'returned_amount' => 0])->assertSessionHasNoErrors();

        $expenditure = Expenditure::query()->findOrFail($voucher->refresh()->expenditure_id);
        $this->assertSame('2026-11-02', $expenditure->spent_at->toDateString());
        $this->assertSame('2026-10', $expenditure->relates_to_period);
    }

    // ---- Item 12: Cameroon time -------------------------------------

    public function test_half_past_midnight_on_the_first_in_cameroon_is_the_new_month(): void
    {
        // 23:30 UTC on 30 November = 00:30 WAT on 1 December.
        $this->travelTo(Carbon::parse('2026-11-30 23:30:00', 'UTC'));

        $voucher = $this->paidBon('5000');

        $this->assertSame('2026-12-01', Expenditure::query()->findOrFail($voucher->expenditure_id)->spent_at->toDateString());

        $this->as('blessing@shalomtech.dev', 'super');
        $this->get('/resources/expenditures')->assertInertia(fn (Assert $page) => $page->where('month', '2026-12'));
    }

    // ---- E9: impersonation ------------------------------------------

    public function test_a_landlord_impersonating_cannot_touch_money(): void
    {
        $landlord = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $landlord->forceFill(['is_landlord' => true])->save();
        $patience = $this->as('patience@shalomtech.dev', 'admin');
        $patience->forceFill(['is_landlord' => false, 'status' => 'active'])->save();

        $this->actingAs($landlord)->post("/landlord/impersonate/{$patience->uuid}")->assertRedirect('/dashboard');

        $vouchersBefore = Voucher::query()->count();
        $this->post('/vouchers', ['kind' => 'expense', 'payee_name' => 'X', 'purpose' => 'Y', 'category_uuid' => $this->category->uuid, 'amount' => 1000])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame($vouchersBefore, Voucher::query()->count());

        foreach ([
            ['post', '/payments'],
            ['post', '/payments/bulk-verify'],
            ['post', '/cash/transfers'],
            ['post', '/cash/remittances'],
            ['post', '/cash/technician-fees/payouts'],
            ['put', '/resources/budgets'],
            ['patch', '/settings/company'],
        ] as [$method, $uri]) {
            $this->{$method}($uri, [])->assertSessionHas('error');
        }

        // Reading is still fine.
        $this->get('/vouchers')->assertOk();
    }
}
