<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AccountingPeriod;
use App\Models\CashAccount;
use App\Models\CashClose;
use App\Models\CashDeposit;
use App\Models\CashHandover;
use App\Models\CashLedgerEntry;
use App\Models\Customer;
use App\Models\Expenditure;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashDeskService;
use App\Services\CashLedgerService;
use Database\Factories\CustomerFactory;
use Database\Factories\ExpenseCategoryFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Resources phase 2 — the cash desk: cash payments and cash expenses move
 * the collector's till automatically, handovers need the receiver's code
 * and count, the daily blind count settles small differences and sends
 * bigger ones to someone else, deposits are confirmed by a second person,
 * and the ledger is append-only and hash-sealed.
 */
class CashDeskTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake('local');
    }

    private function owner(string $role = 'super'): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);

        return $user;
    }

    private function customer(): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
    }

    private function till(User $user): CashAccount
    {
        return app(CashLedgerService::class)->tillFor($user)->fresh();
    }

    private function collectCash(User $collector, int $amount): Payment
    {
        $this->actingAs($collector)->post('/payments', [
            'customer_uuid' => $this->customer()->uuid, 'amount' => $amount, 'frequency' => 'monthly', 'method' => 'cash',
        ])->assertRedirect('/payments');

        return Payment::query()->latest('id')->firstOrFail();
    }

    public function test_a_cash_payment_lands_in_the_collectors_till_and_a_rejection_takes_it_back_out(): void
    {
        $agent = $this->owner('agent');
        $payment = $this->collectCash($agent, 2500);

        $this->assertSame('2500.00', (string) $this->till($agent)->balance);

        $this->actingAs($this->secondStaffUser('manager'))
            ->post("/payments/{$payment->uuid}/verify", ['action' => 'reject', 'notes' => 'Never paid'])
            ->assertRedirect();

        $this->assertSame('0.00', (string) $this->till($agent)->balance);
        $this->assertSame(1, CashLedgerEntry::query()->where('type', 'reversal')->where('source_id', $payment->id)->count());
    }

    public function test_a_momo_payment_goes_to_the_momo_account_not_a_till(): void
    {
        $manager = $this->owner('manager');
        $this->actingAs($manager)->post('/payments', [
            'customer_uuid' => $this->customer()->uuid, 'amount' => 5000, 'frequency' => 'monthly', 'method' => 'mtn_momo', 'momo_ref' => 'MP-'.Str::random(8),
        ])->assertRedirect('/payments');

        $this->assertSame('0.00', (string) $this->till($manager)->balance);
        $this->assertSame('5000.00', (string) CashAccount::query()->where('type', 'mtn_momo')->firstOrFail()->balance);
    }

    public function test_a_cash_expense_comes_out_of_the_till_and_voiding_it_puts_it_back(): void
    {
        $agent = $this->owner('agent');
        $this->collectCash($agent, 5000);
        $category = ExpenseCategoryFactory::new()->create();

        $this->actingAs($agent)->post('/resources/expenditures', [
            'category_uuid' => $category->uuid, 'amount' => 1500, 'spent_at' => Carbon::today()->toDateString(), 'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame('3500.00', (string) $this->till($agent)->balance);

        $expense = Expenditure::query()->latest('id')->firstOrFail();
        $this->actingAs($this->owner('super'))->post("/resources/expenditures/{$expense->uuid}/void", ['reason' => 'Wrong entry'])->assertSessionHas('success');

        $this->assertSame('5000.00', (string) $this->till($agent)->balance);
    }

    public function test_a_handover_moves_cash_only_when_the_receiver_confirms_with_the_code(): void
    {
        $agent = $this->owner('agent');
        $this->collectCash($agent, 10000);
        $cashier = $this->secondStaffUser('manager');
        $cashierTill = $this->till($cashier);

        $handover = app(CashDeskService::class)->startHandover($agent, $cashierTill, '10000');
        $this->assertSame('10000.00', (string) $this->till($agent)->balance, 'Still the giver\'s until confirmed.');

        // The giver can't confirm their own handover…
        $this->actingAs($agent)->post("/cash/handovers/{$handover->uuid}/confirm", ['code' => $handover->code, 'received_amount' => 10000])->assertSessionHasErrors('handover');
        // …and a wrong code is refused.
        $wrong = $handover->code === '000000' ? '111111' : '000000';
        $this->actingAs($cashier)->post("/cash/handovers/{$handover->uuid}/confirm", ['code' => $wrong, 'received_amount' => 10000])->assertSessionHasErrors('code');

        // The cashier counts 9,000: only that moves; 1,000 stays the agent's.
        $this->actingAs($cashier)->post("/cash/handovers/{$handover->uuid}/confirm", ['code' => $handover->code, 'received_amount' => 9000])->assertSessionHas('success');

        $this->assertSame(CashHandover::STATUS_SHORT, $handover->fresh()->status);
        $this->assertSame('1000.00', (string) $this->till($agent)->balance);
        $this->assertSame('9000.00', (string) $this->till($cashier)->balance);
    }

    public function test_you_cannot_hand_over_more_than_you_hold(): void
    {
        $agent = $this->owner('agent');
        $this->collectCash($agent, 2500);

        $this->actingAs($agent)->post('/cash/handovers', [
            'to_account_uuid' => $this->till($this->secondStaffUser())->uuid, 'amount' => 3000,
        ])->assertSessionHasErrors('amount');
    }

    public function test_the_daily_count_settles_small_differences_and_sends_big_ones_to_someone_else(): void
    {
        $agent = $this->owner('agent');
        $this->collectCash($agent, 12500);

        // Counted 12,000 — 500 short: within the tolerance, settled at once.
        $close = app(CashDeskService::class)->submitClose($agent, ['10000' => 1, '2000' => 1]);
        $this->assertSame(CashClose::STATUS_APPROVED, $close->status);
        $this->assertSame('-500.00', (string) $close->variance);
        $this->assertSame('12000.00', (string) $this->till($agent)->balance);
        $this->assertSame(1, CashLedgerEntry::query()->where('type', 'close_short')->where('source_id', $close->id)->count());
    }

    public function test_a_large_shortage_waits_and_the_counter_cannot_approve_it(): void
    {
        $agent = $this->owner('agent');
        $this->collectCash($agent, 20000);

        $close = app(CashDeskService::class)->submitClose($agent, ['10000' => 1, '5000' => 1]);
        $this->assertSame(CashClose::STATUS_PENDING, $close->status);
        $this->assertSame('20000.00', (string) $this->till($agent)->balance, 'Nothing posted until someone decides.');

        $this->owner('manager');
        $this->actingAs($agent)->post("/cash/closes/{$close->uuid}/decide", ['approve' => true])->assertSessionHasErrors('close');

        $this->actingAs($this->secondStaffUser('manager'))->post("/cash/closes/{$close->uuid}/decide", ['approve' => true, 'note' => 'Agent repays'])->assertSessionHas('success');

        $this->assertSame(CashClose::STATUS_APPROVED, $close->fresh()->status);
        $this->assertSame('15000.00', (string) $this->till($agent)->balance);
    }

    public function test_a_shortage_over_ten_thousand_needs_the_owner(): void
    {
        $agent = $this->owner('agent');
        $this->collectCash($agent, 25000);

        $close = app(CashDeskService::class)->submitClose($agent, ['10000' => 1]);

        $this->actingAs($this->secondStaffUser('manager'))->post("/cash/closes/{$close->uuid}/decide", ['approve' => true, 'note' => 'x'])->assertForbidden();
    }

    public function test_a_deposit_is_posted_with_its_slip_and_rejecting_it_reverses_it(): void
    {
        $cashier = $this->owner('manager');
        $this->collectCash($cashier, 30000);
        $bank = CashAccount::query()->create(['type' => 'bank', 'name' => 'Afriland '.Str::random(4)]);

        $this->actingAs($cashier)->post('/cash/deposits', [
            'to_account_uuid' => $bank->uuid, 'amount' => 30000, 'reference' => 'DEP-'.Str::random(6), 'slip' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertSessionHas('success');

        $this->assertSame('0.00', (string) $this->till($cashier)->balance);
        $this->assertSame('30000.00', (string) $bank->fresh()->balance);

        $deposit = CashDeposit::query()->latest('id')->firstOrFail();
        $this->actingAs($cashier)->post("/cash/deposits/{$deposit->uuid}/decide", ['confirm' => true])->assertSessionHasErrors('deposit');

        $this->actingAs($this->secondStaffUser('manager'))->post("/cash/deposits/{$deposit->uuid}/decide", ['confirm' => false, 'note' => 'Not on the statement'])->assertSessionHas('success');

        $this->assertSame('30000.00', (string) $this->till($cashier)->balance);
        $this->assertSame('0.00', (string) $bank->fresh()->balance);
    }

    public function test_the_ledger_is_append_only_and_its_seal_detects_tampering(): void
    {
        $agent = $this->owner('agent');
        $this->collectCash($agent, 2500);
        $this->collectCash($agent, 3000);

        $ledger = app(CashLedgerService::class);
        $this->assertNull($ledger->verify()['broken_at']);
        $this->assertSame([], $ledger->verify()['balance_mismatches']);

        $entry = CashLedgerEntry::query()->latest('id')->firstOrFail();

        try {
            DB::transaction(fn () => DB::table('cash_ledger')->where('id', $entry->id)->update(['amount' => 1]));
            $this->fail('A ledger row was edited.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // A balance changed behind the app's back is caught.
        DB::table('cash_accounts')->where('id', $entry->account_id)->update(['balance' => 999999]);
        $this->assertNotEmpty($ledger->verify()['balance_mismatches']);
    }

    public function test_a_closed_month_refuses_expenses_dated_inside_it(): void
    {
        $owner = $this->owner('super');
        $last = Carbon::now()->subMonthNoOverflow();
        AccountingPeriod::query()->create(['period' => $last->format('Y-m'), 'status' => 'locked', 'locked_by' => $owner->id]);

        $this->actingAs($owner)->post('/resources/expenditures', [
            'category_uuid' => ExpenseCategoryFactory::new()->create()->uuid, 'amount' => 1000, 'spent_at' => $last->startOfMonth()->toDateString(),
        ])->assertSessionHasErrors('spent_at');
    }

    public function test_the_app_shows_my_cash_and_can_count_and_hand_over(): void
    {
        $token = $this->tokenForRole('agent');
        $agent = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->collectCash($agent, 7500);
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/cash/me')
            ->assertOk()
            ->assertJsonPath('data.account.balance', '7500.00')
            ->assertJsonPath('data.today.collected', '7500.00');

        $target = $this->till($this->secondStaffUser());
        $this->owner('agent');

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/cash/handovers', [
            'to_account_uuid' => $target->uuid, 'amount' => 7500, 'denominations' => ['5000' => 1, '2000' => 1, '500' => 1],
        ])->assertCreated()->assertJsonStructure(['data' => ['uuid', 'code']]);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/cash/closes', [
            'denominations' => [], 'unsynced_count' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('unsynced_count');
    }
}
