<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashAlertService;
use App\Services\CashBookService;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Cash controls wave 4 (docs/plans/cash-controls.md): the daily income form
 * (handover → cashier count → till), mobile-money wallet checks, shortages,
 * transfers/deposits, and the till's day close.
 *
 * Two real people are needed for maker ≠ checker: the seeded owner
 * (kelvin, role switched per test) collects, and the seeded admin
 * (patience) receives as cashier.
 */
class CashBookTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function actingAs_(string $email, string $role): User
    {
        $user = $this->user($email);
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function collector(): User
    {
        return $this->actingAs_('kelvin@shalomtech.dev', 'manager');
    }

    private function cashier(): User
    {
        return $this->actingAs_('patience@shalomtech.dev', 'cashier');
    }

    private function customer(): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
    }

    private int $collections = 0;

    /** Records payments as the collector: 2 × 2 500 cash + 5 000 MTN MoMo (its own transaction each time — one transaction pays once). */
    private function collect(): void
    {
        $this->collector();
        $this->collections++;

        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 5000, 'frequency' => 'monthly', 'method' => 'mtn_momo', 'method_reference' => "CI-{$this->collections}"])
            ->assertSessionHasNoErrors();
    }

    private function handOver(string $declared = '5000', ?array $denominations = null): Remittance
    {
        $this->post('/cash/remittances', ['declared_cash' => $declared, 'denominations' => $denominations])->assertSessionHasNoErrors();

        return Remittance::query()->where('collector_user_id', $this->user('kelvin@shalomtech.dev')->id)->latest('id')->firstOrFail();
    }

    private function balanceOf(CashAccount $account): string
    {
        return app(CashBookService::class)->balance($account);
    }

    public function test_the_hand_over_form_lists_what_the_collector_should_hand_over(): void
    {
        $this->collect();

        $this->get('/cash/hand-over')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Cash/HandOver')
            ->has('payments', 3)
            ->where('totals.cash', '5000.00')
            ->where('totals.mtn_momo', '5000.00'));
    }

    public function test_a_handover_is_received_into_the_cashiers_till_and_wallet_lines_into_the_wallet(): void
    {
        $this->collect();
        $remittance = $this->handOver('5000', ['5000' => 1]);

        $this->assertSame('5000.00', (string) $remittance->expected_cash);
        $this->assertSame(3, $remittance->lines()->count());

        // Already handed over: nothing left to hand over.
        $this->post('/cash/remittances', ['declared_cash' => '0'])->assertSessionHasErrors('declared_cash');

        $cashier = $this->cashier();
        $walletLine = $remittance->lines()->where('method', 'mtn_momo')->sole();

        $this->post("/cash/remittances/{$remittance->uuid}/receive", [
            'received_cash' => '5000',
            'denominations' => ['2000' => 2, '1000' => 1],
            'confirmed_lines' => [$walletLine->id],
        ])->assertSessionHasNoErrors();

        $remittance->refresh();
        $this->assertSame('received', $remittance->status);
        $this->assertSame('0.00', (string) $remittance->cash_variance);
        $this->assertNull($remittance->shortage_status);

        $till = app(CashBookService::class)->tillFor($cashier);
        $this->assertSame('5000.00', $this->balanceOf($till));
        $this->assertSame('5000.00', $this->balanceOf(app(CashBookService::class)->walletFor('mtn_momo')));
        $this->assertTrue($walletLine->refresh()->wallet_confirmed);
    }

    public function test_a_short_handover_opens_a_shortage_and_warns_the_office(): void
    {
        $this->collect();
        $remittance = $this->handOver('5000');

        $this->cashier();
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '4000'])->assertSessionHasNoErrors();

        $remittance->refresh();
        $this->assertSame('-1000.00', (string) $remittance->cash_variance);
        $this->assertSame('open', $remittance->shortage_status);
        $this->assertTrue(Notification::query()->where('type', 'remittance.shortage')->where('source_uuid', $remittance->uuid)->exists());

        // The collector repays — the cashier receives the missing 1 000.
        $this->post("/cash/remittances/{$remittance->uuid}/shortage", ['outcome' => 'repaid'])->assertSessionHasNoErrors();
        $this->assertSame('repaid', $remittance->refresh()->shortage_status);
        $this->assertSame('5000.00', $this->balanceOf(app(CashBookService::class)->tillFor($this->user('patience@shalomtech.dev'))));
    }

    public function test_a_cashier_cannot_write_off_a_shortage_but_a_manager_can(): void
    {
        $this->collect();
        $remittance = $this->handOver('5000');
        $this->cashier();
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '3000']);

        $this->post("/cash/remittances/{$remittance->uuid}/shortage", ['outcome' => 'written_off', 'note' => 'Lost'])->assertForbidden();

        $this->actingAs_('terence@shalomtech.dev', 'manager');
        $this->post("/cash/remittances/{$remittance->uuid}/shortage", ['outcome' => 'written_off', 'note' => 'Robbed — police report 12'])->assertSessionHasNoErrors();

        $this->assertSame('written_off', $remittance->refresh()->shortage_status);
    }

    public function test_a_collector_cannot_receive_their_own_handover(): void
    {
        $this->collect();
        $remittance = $this->handOver('5000');

        $this->actingAs_('kelvin@shalomtech.dev', 'cashier');

        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '5000'])->assertForbidden();
    }

    public function test_declared_notes_must_add_up(): void
    {
        $this->collect();

        $this->post('/cash/remittances', ['declared_cash' => '5000', 'denominations' => ['2000' => 1]])->assertSessionHasErrors('declared_cash');
    }

    public function test_handed_over_money_can_no_longer_be_edited_or_deleted(): void
    {
        $this->collect();
        $this->handOver('5000');
        $payment = Payment::query()->where('recorded_by', $this->user('kelvin@shalomtech.dev')->id)->where('method', 'cash')->latest('id')->firstOrFail();

        $this->actingAs_('kelvin@shalomtech.dev', 'admin');
        $this->put("/payments/{$payment->uuid}", ['amount' => 9000])->assertSessionHasErrors('amount');
        $this->delete("/payments/{$payment->uuid}")->assertSessionHasErrors('payment');
    }

    public function test_payments_from_before_cash_controls_started_are_not_asked_for(): void
    {
        $this->collect();
        Payment::query()->where('recorded_by', $this->user('kelvin@shalomtech.dev')->id)->update(['created_at' => now()->subYear()]);

        $this->get('/cash/hand-over')->assertInertia(fn (Assert $page) => $page->has('payments', 0));
    }

    public function test_a_deposit_moves_money_from_the_till_to_the_bank_with_a_slip_number(): void
    {
        $this->collect();
        $remittance = $this->handOver('5000');
        $cashier = $this->cashier();
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '5000']);

        $till = app(CashBookService::class)->tillFor($cashier);
        $bank = app(CashBookService::class)->createAccount(CashAccount::KIND_BANK, 'Afriland — main', null, '0012345');

        Storage::fake(config('filesystems.default', 'local'));
        $slip = fn (): UploadedFile => UploadedFile::fake()->image('slip-'.uniqid().'.jpg');

        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $bank->uuid, 'amount' => 4000, 'slip_photo' => $slip()])->assertSessionHasErrors('reference');
        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $bank->uuid, 'amount' => 4000, 'reference' => 'SLIP-1'])->assertSessionHasErrors('slip_photo');
        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $bank->uuid, 'amount' => 9000, 'reference' => 'SLIP-1', 'slip_photo' => $slip()])->assertSessionHasErrors('amount');
        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $bank->uuid, 'amount' => 4000, 'reference' => 'SLIP-1', 'slip_photo' => $slip()])->assertSessionHasNoErrors();

        // Out of the till at once; in the bank only once confirmed (CashHandoversAndDepositsTest).
        $this->assertSame('1000.00', $this->balanceOf($till));
        $this->assertSame('0.00', $this->balanceOf($bank));
        $this->assertStringStartsWith('DP-', (string) CashMovement::query()->where('cash_account_id', $till->id)->where('kind', CashMovement::KIND_DEPOSIT)->value('document_number'));
    }

    public function test_closing_the_day_records_a_difference_and_locks_the_till(): void
    {
        $this->collect();
        $first = $this->handOver('5000');
        $cashier = $this->cashier();
        $this->post("/cash/remittances/{$first->uuid}/receive", ['received_cash' => '5000']);
        $till = app(CashBookService::class)->tillFor($cashier);

        $this->post("/cash/accounts/{$till->uuid}/close-day", ['counted' => '4500'])->assertSessionHasNoErrors();

        $this->assertSame('4500.00', $this->balanceOf($till));
        $this->assertTrue(app(CashBookService::class)->isDayClosed($till));

        // A closed till refuses money until a supervisor reopens it.
        $this->collect();
        $second = $this->handOver('5000');
        $this->cashier();
        $this->post("/cash/remittances/{$second->uuid}/receive", ['received_cash' => '5000'])->assertSessionHasErrors('account');

        $this->post("/cash/accounts/{$till->uuid}/reopen-day", ['reason' => 'Late handover'])->assertForbidden();

        $this->actingAs_('terence@shalomtech.dev', 'manager');
        $this->post("/cash/accounts/{$till->uuid}/reopen-day", ['reason' => 'Late handover'])->assertSessionHasNoErrors();

        $this->cashier();
        $this->post("/cash/remittances/{$second->uuid}/receive", ['received_cash' => '5000'])->assertSessionHasNoErrors();
    }

    public function test_cash_movements_are_append_only(): void
    {
        $this->collect();
        $remittance = $this->handOver('5000');
        $this->cashier();
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '5000']);

        $movement = CashMovement::query()->latest('id')->firstOrFail();

        $this->expectException(LogicException::class);
        $movement->update(['amount' => 1]);
    }

    public function test_a_momo_payment_not_seen_at_the_handover_stays_owed_until_it_is_found(): void
    {
        $this->collect();
        $remittance = $this->handOver('5000');
        $this->cashier();
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '5000'])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'not found'));

        // Owner, 2026-10-28: it no longer just drops — the collector owes it, and the alert stays until it is settled.
        $line = $remittance->lines()->where('method', 'mtn_momo')->sole();
        $this->assertTrue($line->isOwed());
        $this->assertNotEmpty(collect(app(CashAlertService::class)->alerts())->firstWhere('key', 'wallet_not_found')['items'] ?? []);

        // Never settled by the collector who owes it.
        $this->collector();
        $this->post("/cash/remittances/{$remittance->uuid}/lines/{$line->id}/settle", ['outcome' => 'found']);
        $this->assertTrue($line->refresh()->isOwed());

        // Seen on the statement after all: booked into the wallet.
        $cashier = $this->cashier();
        $this->post("/cash/remittances/{$remittance->uuid}/lines/{$line->id}/settle", ['outcome' => 'found'])->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame('found', $line->outcome);
        $this->assertSame($cashier->id, $line->settled_by);
        $this->assertSame('5000.00', $this->balanceOf(app(CashBookService::class)->walletFor('mtn_momo')));
        $this->assertNull(collect(app(CashAlertService::class)->alerts())->firstWhere('key', 'wallet_not_found'));
    }

    public function test_a_momo_payment_never_found_is_paid_in_cash_or_written_off(): void
    {
        $this->collect();
        $first = $this->handOver('5000');
        $cashier = $this->cashier();
        $this->post("/cash/remittances/{$first->uuid}/receive", ['received_cash' => '5000']);
        $line = $first->lines()->where('method', 'mtn_momo')->sole();

        // The collector pays it in cash: into the cashier's till.
        $this->post("/cash/remittances/{$first->uuid}/lines/{$line->id}/settle", ['outcome' => 'repaid'])->assertSessionHasNoErrors();
        $this->assertSame('repaid', $line->refresh()->outcome);
        $this->assertSame('10000.00', $this->balanceOf(app(CashBookService::class)->tillFor($cashier)));

        $this->collect();
        $second = $this->handOver('5000');
        $this->cashier();
        $this->post("/cash/remittances/{$second->uuid}/receive", ['received_cash' => '5000']);
        $other = $second->lines()->where('method', 'mtn_momo')->sole();

        // A loss is a manager's decision, with a reason — not the cashier's.
        $this->post("/cash/remittances/{$second->uuid}/lines/{$other->id}/settle", ['outcome' => 'written_off', 'note' => 'Fake ID'])->assertForbidden();
        $this->actingAs_('terence@shalomtech.dev', 'manager');
        $this->post("/cash/remittances/{$second->uuid}/lines/{$other->id}/settle", ['outcome' => 'written_off'])->assertSessionHasErrors('note');
        $this->post("/cash/remittances/{$second->uuid}/lines/{$other->id}/settle", ['outcome' => 'written_off', 'note' => 'Transaction ID was fake'])->assertSessionHasNoErrors();
        $this->assertSame('written_off', $other->refresh()->outcome);
    }

    public function test_a_bank_payment_is_ticked_into_the_branch_bank_account(): void
    {
        $bank = app(CashBookService::class)->createAccount(CashAccount::KIND_BANK, 'Afriland', null, '0001');
        $this->collector();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 30000, 'frequency' => 'monthly', 'method' => 'bank', 'method_reference' => 'SLIP-9'])
            ->assertSessionHasNoErrors();
        $remittance = $this->handOver('0');

        $this->cashier();
        $line = $remittance->lines()->sole();
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '0', 'confirmed_lines' => [$line->id]])->assertSessionHasNoErrors();

        $this->assertSame('found', $line->refresh()->outcome);
        $this->assertSame($bank->id, $line->cash_account_id);
        $this->assertSame('30000.00', $this->balanceOf($bank));
    }

    public function test_cash_taken_at_the_desk_goes_straight_into_the_till(): void
    {
        $cashier = $this->cashier();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();

        // Owner, 2026-10-28: in the drawer and in the book at once — never left as the cashier's outstanding cash.
        $till = app(CashBookService::class)->tillFor($cashier);
        $this->assertSame('2500.00', $this->balanceOf($till));
        $this->assertSame(CashMovement::KIND_DESK_PAYMENT, CashMovement::query()->where('cash_account_id', $till->id)->value('kind'));
        $this->assertTrue(Remittance::query()->where('collector_user_id', $cashier->id)->sole()->at_desk);
        $this->get('/cash/hand-over')->assertInertia(fn (Assert $page) => $page->has('payments', 0));
    }

    public function test_momo_taken_at_the_desk_still_goes_through_a_handover(): void
    {
        $cashier = $this->cashier();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'mtn_momo', 'method_reference' => 'CI-D1'])
            ->assertSessionHasNoErrors();

        // Someone else must tick it off the wallet statement.
        $this->assertSame('0.00', $this->balanceOf(app(CashBookService::class)->tillFor($cashier)));
        $this->get('/cash/hand-over')->assertInertia(fn (Assert $page) => $page->has('payments', 1));
    }

    public function test_nobody_receives_their_own_handover_not_even_the_owner(): void
    {
        $this->collect();
        $remittance = $this->handOver('5000');

        $this->actingAs_('kelvin@shalomtech.dev', 'super');
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '5000'])->assertForbidden();
        $this->assertTrue($remittance->refresh()->isSubmitted());
    }

    public function test_extra_cash_covers_the_collectors_next_shortage(): void
    {
        $this->collect();
        $first = $this->handOver('6000');
        $this->cashier();
        $this->post("/cash/remittances/{$first->uuid}/receive", ['received_cash' => '6000'])->assertSessionHasNoErrors();
        $this->assertSame('1000.00', (string) $first->refresh()->credit_remaining);
        $this->assertSame('1000.00', collect(app(CashAlertService::class)->alerts())->firstWhere('key', 'collector_credits')['items'][0]['amount']);

        // 1,500 short next time: the 1,000 already handed in covers part of it, 500 stays owed.
        $this->collect();
        $second = $this->handOver('3500');
        $this->cashier();
        $this->post("/cash/remittances/{$second->uuid}/receive", ['received_cash' => '3500'])->assertSessionHasNoErrors();

        $second->refresh();
        $this->assertSame('open', $second->shortage_status);
        $this->assertSame('1000.00', (string) $second->shortage_covered);
        $this->assertSame('500.00', $second->shortageOwed());
        $this->assertSame('0.00', (string) $first->refresh()->credit_remaining);

        // Repaying it takes only what is still owed.
        $cashier = $this->cashier();
        $before = $this->balanceOf(app(CashBookService::class)->tillFor($cashier));
        $this->post("/cash/remittances/{$second->uuid}/shortage", ['outcome' => 'repaid'])->assertSessionHasNoErrors();
        $this->assertSame(bcadd($before, '500', 2), $this->balanceOf(app(CashBookService::class)->tillFor($cashier)));
    }

    public function test_access(): void
    {
        $this->actingAs_('kelvin@shalomtech.dev', 'agent');
        $this->get('/cash')->assertRedirect('/cash/hand-over');
        $this->get('/cash/hand-over')->assertOk();

        $this->actingAs_('kelvin@shalomtech.dev', 'worker');
        $this->get('/cash')->assertForbidden();
        $this->get('/cash/hand-over')->assertForbidden();

        $this->actingAs_('kelvin@shalomtech.dev', 'manager');
        $this->get('/cash')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Cash/Index')->where('can.view_all', true));
    }

    public function test_the_app_can_preview_and_submit_a_handover(): void
    {
        $this->collect();
        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/remittances/preview')
            ->assertOk()->assertJsonPath('data.count', 3)->assertJsonPath('data.totals.cash', '5000.00');

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/remittances', ['declared_cash' => 5000])
            ->assertCreated()->assertJsonPath('data.status', 'submitted');
    }
}
