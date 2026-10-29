<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\CashAccount;
use App\Models\CashDayClose;
use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\CashStatementCount;
use App\Models\CashTransfer;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashAlertService;
use App\Services\CashBookService;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Money must balance at every level, wave 3 — accounts (owner, 2026-10-28):
 * money for another person's till or the bank waits for someone else;
 * whatever is missing is owned by whoever last held it; yesterday's till
 * is closed before today's; monthly statements; nobody leaves holding money;
 * opening balances only before the branch's cash book starts.
 *
 * People: patience (cashier), kelvin and terence (admins — a till, cash.view,
 * vouchers.approve).
 */
class CashHandoversAndDepositsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private CashBookService $cashBook;

    private int $slips = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake(config('filesystems.default', 'local'));
        $this->cashBook = app(CashBookService::class);
    }

    private function as(string $email, string $role = 'admin'): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }

    private function cashier(): User
    {
        return $this->as('patience@shalomtech.dev', 'cashier');
    }

    private function kelvin(): User
    {
        return $this->as('kelvin@shalomtech.dev');
    }

    private function terence(): User
    {
        return $this->as('terence@shalomtech.dev');
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** A till holding $amount, booked today. */
    private function tillWith(string $email, string $amount): CashAccount
    {
        $holder = $this->user($email);
        $till = $this->cashBook->tillFor($holder);
        $this->cashBook->record($till, 'in', $amount, CashMovement::KIND_TRANSFER, $holder, ['note' => 'test float']);

        return $till;
    }

    private function bank(): CashAccount
    {
        return $this->cashBook->createAccount(CashAccount::KIND_BANK, 'Afriland '.uniqid(), null, '001');
    }

    /** A different slip photo each time (its size changes its bytes). */
    private function slip(): UploadedFile
    {
        return UploadedFile::fake()->image('slip.jpg', 100 + ++$this->slips, 100);
    }

    private function balance(CashAccount $account): string
    {
        return $this->cashBook->balance($account);
    }

    public function test_a_pickup_waits_for_the_manager_and_a_short_count_is_the_cashiers_shortage(): void
    {
        $cashierTill = $this->tillWith('patience@shalomtech.dev', '10000');
        $managerTill = $this->cashBook->tillFor($this->user('kelvin@shalomtech.dev'));

        $cashier = $this->cashier();
        $this->post('/cash/transfers', ['from_account' => $cashierTill->uuid, 'to_account' => $managerTill->uuid, 'amount' => 8000])->assertSessionHasNoErrors();

        $handover = CashTransfer::query()->latest('id')->firstOrFail();
        $this->assertSame([CashTransfer::KIND_HANDOVER, CashTransfer::STATUS_SENT], [$handover->kind, $handover->status]);
        $this->assertSame('2000.00', $this->balance($cashierTill), 'out of the cashier\'s till at once');
        $this->assertSame('0.00', $this->balance($managerTill), 'not the manager\'s until accepted');

        // The cashier cannot accept their own handover.
        $this->post("/cash/transfers/{$handover->uuid}/accept", ['counted' => 8000])->assertSessionHasErrors('counted');

        $this->kelvin();
        $this->post("/cash/transfers/{$handover->uuid}/accept", ['counted' => 9000])->assertSessionHasErrors('counted');
        $this->post("/cash/transfers/{$handover->uuid}/accept", ['counted' => 7500])->assertSessionHasNoErrors();

        $this->assertSame('7500.00', $this->balance($managerTill));
        $this->assertSame('7500.00', (string) $handover->fresh()->received_amount);
        $shortage = CashShortage::query()->open()->where('source_id', $handover->id)->sole();
        $this->assertSame([$cashier->id, CashShortage::KIND_HANDOVER, '500.00'], [$shortage->owner_user_id, $shortage->kind, (string) $shortage->amount]);
    }

    public function test_a_handover_is_recalled_by_its_sender_or_refused_by_the_receiver(): void
    {
        $cashierTill = $this->tillWith('patience@shalomtech.dev', '10000');
        $managerTill = $this->cashBook->tillFor($this->user('kelvin@shalomtech.dev'));

        $this->cashier();
        $this->post('/cash/transfers', ['from_account' => $cashierTill->uuid, 'to_account' => $managerTill->uuid, 'amount' => 3000]);
        $first = CashTransfer::query()->latest('id')->firstOrFail();

        // A till with a handover still open is not closed.
        $this->post("/cash/accounts/{$cashierTill->uuid}/close-day", ['counted' => '7000'])->assertSessionHasErrors('counted');

        $this->post("/cash/transfers/{$first->uuid}/recall")->assertSessionHasNoErrors();
        $this->assertSame([CashTransfer::STATUS_RECALLED, '10000.00'], [$first->fresh()->status, $this->balance($cashierTill)]);

        $this->post('/cash/transfers', ['from_account' => $cashierTill->uuid, 'to_account' => $managerTill->uuid, 'amount' => 3000]);
        $second = CashTransfer::query()->latest('id')->firstOrFail();

        $this->kelvin();
        $this->post("/cash/transfers/{$second->uuid}/recall")->assertSessionHasErrors('transfer');
        $this->post("/cash/transfers/{$second->uuid}/refuse", ['reason' => 'Not counted together'])->assertSessionHasNoErrors();

        $this->assertSame([CashTransfer::STATUS_REJECTED, '10000.00', '0.00'], [$second->fresh()->status, $this->balance($cashierTill), $this->balance($managerTill)]);
    }

    public function test_nobody_moves_money_out_of_someone_elses_till(): void
    {
        $cashierTill = $this->tillWith('patience@shalomtech.dev', '10000');
        $safe = $this->cashBook->createAccount(CashAccount::KIND_SAFE, 'Safe '.uniqid(), null, null);

        $this->kelvin();
        $this->post('/cash/transfers', ['from_account' => $cashierTill->uuid, 'to_account' => $safe->uuid, 'amount' => 5000])->assertSessionHasErrors('from_account');

        $this->assertSame('10000.00', $this->balance($cashierTill));
    }

    public function test_a_deposit_is_in_transit_until_two_other_people_confirm_it(): void
    {
        $managerTill = $this->tillWith('kelvin@shalomtech.dev', '10000');
        $bank = $this->bank();

        $this->kelvin();
        $this->post('/cash/transfers', ['from_account' => $managerTill->uuid, 'to_account' => $bank->uuid, 'amount' => 6000, 'reference' => 'SL-77', 'slip_photo' => $this->slip()])->assertSessionHasNoErrors();
        $deposit = CashTransfer::query()->latest('id')->firstOrFail();
        $this->assertSame([CashTransfer::KIND_DEPOSIT, 2], [$deposit->kind, $deposit->required_confirmations]);
        $this->assertSame(['4000.00', '0.00'], [$this->balance($managerTill), $this->balance($bank)]);

        // The same slip number on the same bank is refused.
        $this->post('/cash/transfers', ['from_account' => $managerTill->uuid, 'to_account' => $bank->uuid, 'amount' => 1000, 'reference' => 'sl-77', 'slip_photo' => $this->slip()])->assertSessionHasErrors('slip_number');

        // The depositor never confirms.
        $this->post("/cash/transfers/{$deposit->uuid}/confirm")->assertSessionHasErrors('deposit');

        $this->cashier();
        $this->post("/cash/transfers/{$deposit->uuid}/confirm")->assertSessionHasNoErrors();
        $this->post("/cash/transfers/{$deposit->uuid}/confirm")->assertSessionHasErrors('deposit');
        $this->assertSame([CashTransfer::STATUS_SENT, '0.00'], [$deposit->fresh()->status, $this->balance($bank)]);

        $this->terence();
        $this->post("/cash/transfers/{$deposit->uuid}/confirm")->assertSessionHasNoErrors();
        $this->assertSame([CashTransfer::STATUS_RECEIVED, '6000.00'], [$deposit->fresh()->status, $this->balance($bank)]);
    }

    public function test_a_slip_photo_is_never_used_twice(): void
    {
        $managerTill = $this->tillWith('kelvin@shalomtech.dev', '10000');
        $bank = $this->bank();
        $photo = $this->slip();

        $this->kelvin();
        $this->post('/cash/transfers', ['from_account' => $managerTill->uuid, 'to_account' => $bank->uuid, 'amount' => 1000, 'reference' => 'A-1', 'slip_photo' => $photo])->assertSessionHasNoErrors();
        $this->post('/cash/transfers', ['from_account' => $managerTill->uuid, 'to_account' => $bank->uuid, 'amount' => 1000, 'reference' => 'A-2', 'slip_photo' => $photo])->assertSessionHasErrors('slip_photo');
    }

    public function test_a_deposit_not_all_in_the_bank_credits_what_is_there_and_the_rest_is_the_depositors_shortage(): void
    {
        $managerTill = $this->tillWith('kelvin@shalomtech.dev', '10000');
        $bank = $this->bank();

        $depositor = $this->kelvin();
        $this->post('/cash/transfers', ['from_account' => $managerTill->uuid, 'to_account' => $bank->uuid, 'amount' => 6000, 'reference' => 'SL-9', 'slip_photo' => $this->slip()]);
        $deposit = CashTransfer::query()->latest('id')->firstOrFail();

        $this->terence();
        $this->post("/cash/transfers/{$deposit->uuid}/refuse-deposit", ['found' => 6000, 'reason' => 'All there'])->assertSessionHasErrors('found');
        $this->post("/cash/transfers/{$deposit->uuid}/refuse-deposit", ['found' => 4000, 'reason' => 'Statement shows 4,000'])->assertSessionHasNoErrors();

        $this->assertSame([CashTransfer::STATUS_REJECTED, '4000.00'], [$deposit->fresh()->status, $this->balance($bank)]);
        $shortage = CashShortage::query()->open()->where('kind', CashShortage::KIND_DEPOSIT)->sole();
        $this->assertSame([$depositor->id, '2000.00'], [$shortage->owner_user_id, (string) $shortage->amount]);
    }

    public function test_a_day_close_shortage_is_the_holders_and_a_reclose_never_rewrites_it(): void
    {
        $till = $this->tillWith('patience@shalomtech.dev', '10000');

        $cashier = $this->cashier();
        $this->post("/cash/accounts/{$till->uuid}/close-day", ['counted' => '9000'])->assertSessionHasNoErrors();

        $this->kelvin();
        $this->post("/cash/accounts/{$till->uuid}/reopen-day", ['reason' => 'Found a receipt'])->assertSessionHasNoErrors();

        $this->cashier();
        $this->post("/cash/accounts/{$till->uuid}/close-day", ['counted' => '8500'])->assertSessionHasNoErrors();

        $close = CashDayClose::query()->where('cash_account_id', $till->id)->sole();
        $this->assertSame(['-1500.00', '10000.00', '8500.00'], [(string) $close->variance, (string) $close->expected, (string) $close->counted], 'the whole day\'s difference');
        $this->assertSame(['1000.00', '500.00'], CashShortage::query()->open()->where('owner_user_id', $cashier->id)->where('kind', CashShortage::KIND_TILL_CLOSE)->orderBy('id')->pluck('amount')->map(fn ($amount): string => (string) $amount)->all());
    }

    public function test_a_shortage_is_settled_by_someone_else_repaid_into_their_till_or_written_off_with_a_reason(): void
    {
        $till = $this->tillWith('patience@shalomtech.dev', '10000');
        $this->cashier();
        $this->post("/cash/accounts/{$till->uuid}/close-day", ['counted' => '9000']);
        $shortage = CashShortage::query()->open()->latest('id')->firstOrFail();

        $this->post("/cash/shortages/{$shortage->uuid}/settle", ['outcome' => 'repaid'])->assertSessionHasErrors('outcome');

        $settler = $this->kelvin();
        $this->post("/cash/shortages/{$shortage->uuid}/settle", ['outcome' => 'repaid', 'note' => 'Paid back in cash'])->assertSessionHasNoErrors();

        $this->assertSame(CashShortage::STATUS_REPAID, $shortage->fresh()->status);
        $this->assertSame('1000.00', $this->balance($this->cashBook->tillFor($settler)));

        $other = CashShortage::query()->create(['owner_user_id' => $this->user('patience@shalomtech.dev')->id, 'branch_id' => $till->branch_id, 'kind' => CashShortage::KIND_HANDOVER, 'amount' => 300]);
        $this->post("/cash/shortages/{$other->uuid}/settle", ['outcome' => 'written_off'])->assertSessionHasErrors('note');
        $this->post("/cash/shortages/{$other->uuid}/settle", ['outcome' => 'written_off', 'note' => 'Torn note'])->assertSessionHasNoErrors();
        $this->assertSame(CashShortage::STATUS_WRITTEN_OFF, $other->fresh()->status);
    }

    public function test_yesterdays_till_day_is_closed_before_today_and_a_manager_closes_it_for_an_absent_cashier(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Africa/Lagos'));
        $till = $this->tillWith('patience@shalomtech.dev', '10000');
        $safe = $this->cashBook->createAccount(CashAccount::KIND_SAFE, 'Safe '.uniqid(), null, null);

        $this->travelTo(Carbon::parse('2026-11-04 09:00', 'Africa/Lagos'));
        $this->cashier();
        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $safe->uuid, 'amount' => 1000])
            ->assertSessionHasErrors(['account' => "{$till->name} still has 2026-11-02 open — count the drawer and close that day first."]);

        // The cashier is away: a manager counts it; the difference is still theirs.
        $this->kelvin();
        $this->post("/cash/accounts/{$till->uuid}/close-day", ['counted' => '9500'])->assertSessionHasNoErrors();

        $close = CashDayClose::query()->where('cash_account_id', $till->id)->sole();
        $this->assertSame('2026-11-02', $close->business_date->toDateString());
        $shortage = CashShortage::query()->open()->where('kind', CashShortage::KIND_TILL_CLOSE)->latest('id')->firstOrFail();
        $this->assertSame($this->user('patience@shalomtech.dev')->id, $shortage->owner_user_id);
        $this->assertStringContainsString('in their absence', (string) $shortage->note);

        $this->cashier();
        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $safe->uuid, 'amount' => 1000])->assertSessionHasNoErrors();
        $this->assertSame('8500.00', $this->balance($till));
    }

    public function test_a_monthly_statement_is_counted_against_the_book_and_a_difference_stays_on_the_alerts(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Africa/Lagos'));
        $bank = $this->bank();
        $actor = $this->user('kelvin@shalomtech.dev');
        $this->cashBook->record($bank, 'in', '50000', CashMovement::KIND_DEPOSIT, $actor, ['business_date' => '2026-10-20']);
        $till = $this->cashBook->tillFor($actor);

        $this->kelvin();
        $this->post("/cash/accounts/{$till->uuid}/statement", ['period' => '2026-10', 'statement_balance' => 0])->assertForbidden();
        $this->post("/cash/accounts/{$bank->uuid}/statement", ['period' => '2026-11', 'statement_balance' => 0])->assertSessionHasErrors('period');
        $this->post("/cash/accounts/{$bank->uuid}/statement", ['period' => '2026-10', 'statement_balance' => 48500])->assertSessionHasNoErrors();

        $count = CashStatementCount::query()->where('cash_account_id', $bank->id)->sole();
        $this->assertSame(['50000.00', '-1500.00'], [(string) $count->book_balance, (string) $count->difference]);
        $this->assertContains('statement_differences', array_column(app(CashAlertService::class)->alerts(), 'key'));

        // Bank charges booked, counted again: it matches and the alert goes.
        $this->cashBook->record($bank, 'out', '1500', CashMovement::KIND_TRANSFER, $actor, ['business_date' => '2026-10-31', 'note' => 'Bank charges']);
        $this->post("/cash/accounts/{$bank->uuid}/statement", ['period' => '2026-10', 'statement_balance' => 48500])->assertSessionHasNoErrors();
        $this->assertSame('0.00', (string) $count->fresh()->difference);
    }

    public function test_opening_balances_only_before_the_branchs_cash_book_starts_and_never_on_a_till(): void
    {
        $branch = BranchFactory::new()->create();
        $safe = CashAccount::query()->create(['kind' => CashAccount::KIND_SAFE, 'name' => 'New safe', 'is_active' => true, 'branch_id' => $branch->id]);
        $bank = CashAccount::query()->create(['kind' => CashAccount::KIND_BANK, 'name' => 'New bank', 'is_active' => true, 'branch_id' => $branch->id]);
        $till = $this->cashBook->tillFor($this->user('patience@shalomtech.dev'));

        $this->kelvin();
        $this->post("/cash/accounts/{$till->uuid}/opening-balance", ['amount' => 1000])->assertSessionHasErrors('amount');
        $this->post("/cash/accounts/{$safe->uuid}/opening-balance", ['amount' => 20000])->assertSessionHasNoErrors();
        $this->post("/cash/accounts/{$bank->uuid}/opening-balance", ['amount' => 90000])->assertSessionHasNoErrors();

        $late = CashAccount::query()->create(['kind' => CashAccount::KIND_SAFE, 'name' => 'Later safe', 'is_active' => true, 'branch_id' => $branch->id]);
        $this->cashBook->record($safe, 'out', '5000', CashMovement::KIND_TRANSFER, $this->user('kelvin@shalomtech.dev'));
        $this->post("/cash/accounts/{$late->uuid}/opening-balance", ['amount' => 5000])->assertSessionHasErrors('amount');
    }

    public function test_nobody_is_deactivated_while_holding_the_companys_money(): void
    {
        $this->tillWith('patience@shalomtech.dev', '10000');
        $tenantUser = TenantUser::query()->where('user_id', $this->user('patience@shalomtech.dev')->id)->sole();

        $this->kelvin();
        $this->post("/users/{$tenantUser->id}/deactivate")->assertSessionHasErrors('user');
        $this->assertSame('active', $tenantUser->user->fresh()->status);
    }

    public function test_a_branch_transfer_received_short_is_the_senders_shortage(): void
    {
        $buea = BranchFactory::new()->create(['name' => 'Buea '.uniqid()]);
        $bueaSafe = CashAccount::query()->create(['kind' => CashAccount::KIND_SAFE, 'name' => 'Buea safe', 'is_active' => true, 'branch_id' => $buea->id]);
        $sender = $this->user('kelvin@shalomtech.dev');
        $this->cashBook->record($bueaSafe, 'in', '50000', CashMovement::KIND_TRANSFER, $sender);
        $hqSafe = CashAccount::query()->create(['kind' => CashAccount::KIND_SAFE, 'name' => 'HQ safe', 'is_active' => true, 'branch_id' => Branch::headquartersId()]);

        $this->kelvin();
        $this->post('/cash/branch-transfers', ['from_account' => $bueaSafe->uuid, 'to_branch' => Branch::query()->whereKey(Branch::headquartersId())->value('uuid'), 'amount' => '50000'])->assertSessionHasNoErrors();
        $transfer = BranchTransfer::query()->latest('id')->firstOrFail();

        // Never received by its sender.
        $this->post("/cash/branch-transfers/{$transfer->uuid}/receive", ['account' => $hqSafe->uuid])->assertSessionHasErrors('transfer');

        $this->terence();
        $this->post("/cash/branch-transfers/{$transfer->uuid}/receive", ['account' => $hqSafe->uuid, 'received_amount' => '45000'])->assertSessionHasNoErrors();

        $this->assertSame('45000.00', $this->balance($hqSafe));
        $shortage = CashShortage::query()->open()->where('kind', CashShortage::KIND_BRANCH_TRANSFER)->sole();
        $this->assertSame([$sender->id, '5000.00'], [$shortage->owner_user_id, (string) $shortage->amount]);
    }
}
