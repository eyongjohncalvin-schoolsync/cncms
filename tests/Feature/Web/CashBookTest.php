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
use App\Services\CashBookService;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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

    /** Records payments as the collector: 2 × 2 500 cash + 5 000 MTN MoMo. */
    private function collect(): void
    {
        $this->collector();

        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 5000, 'frequency' => 'monthly', 'method' => 'mtn_momo', 'method_reference' => 'CI-1'])
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

        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $bank->uuid, 'amount' => 4000])->assertSessionHasErrors('reference');
        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $bank->uuid, 'amount' => 9000, 'reference' => 'SLIP-1'])->assertSessionHasErrors('amount');
        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $bank->uuid, 'amount' => 4000, 'reference' => 'SLIP-1'])->assertSessionHasNoErrors();

        $this->assertSame('1000.00', $this->balanceOf($till));
        $this->assertSame('4000.00', $this->balanceOf($bank));
        $this->assertStringStartsWith('DP-', (string) CashMovement::query()->where('cash_account_id', $bank->id)->value('document_number'));
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
