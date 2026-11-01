<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\CashTransfer;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Remittance;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashBookService;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Cash on the phone (docs/plans/mobile-manager-app.md, wave 4): the
 * cashier receives a handover, closes the day; money handed to someone
 * is accepted from the phone; deposits are confirmed; shortages settled;
 * and each of those reaches the right person's phone and inbox. Seeded
 * swecom members: kelvin collects, patience is the cashier, terence the
 * manager, divine an agent with no cash book.
 */
class CashOnPhoneTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private CashBookService $cashBook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake(config('filesystems.default', 'local'));
        $this->cashBook = app(CashBookService::class);
        TenantUser::query()->where('tenant_id', 'swecom')->update(['branch_id' => null, 'is_investor' => false]);

        $this->role('kelvin@shalomtech.dev', 'manager');
        $this->role('patience@shalomtech.dev', 'cashier');
        $this->role('terence@shalomtech.dev', 'admin');
        $this->role('divine@shalomtech.dev', 'agent');
    }

    private function role(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        return $user;
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** The phone, signed in as this person. */
    private function phone(string $email): User
    {
        $user = $this->user($email);
        app()->forgetInstance(TenantContext::class);
        Sanctum::actingAs($user);

        return $user;
    }

    private function customer(): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
    }

    /** Kelvin collects 2 × 2 500 cash + 5 000 MoMo on the web and hands it over. */
    private function handOver(): Remittance
    {
        $this->actingAs($this->user('kelvin@shalomtech.dev'));
        app()->forgetInstance(TenantContext::class);

        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 5000, 'frequency' => 'monthly', 'method' => 'mtn_momo', 'method_reference' => 'CI-'.uniqid()])->assertSessionHasNoErrors();
        $this->post('/cash/remittances', ['declared_cash' => '5000'])->assertSessionHasNoErrors();

        return Remittance::query()->latest('id')->firstOrFail();
    }

    private function tillWith(string $email, string $amount): CashAccount
    {
        $holder = $this->user($email);
        $till = $this->cashBook->tillFor($holder);
        $this->cashBook->record($till, 'in', $amount, CashMovement::KIND_TRANSFER, $holder, ['note' => 'test float']);

        return $till;
    }

    public function test_the_cashier_is_alerted_and_receives_a_handover_from_the_phone(): void
    {
        $remittance = $this->handOver();
        $cashier = $this->user('patience@shalomtech.dev');

        $alerted = Notification::query()->where('type', 'cash.handover')->where('source_uuid', $remittance->uuid)->pluck('recipient_user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($cashier->id, $alerted);
        $this->assertNotContains($this->user('kelvin@shalomtech.dev')->id, $alerted, 'Never the collector themselves.');

        $this->phone('patience@shalomtech.dev');
        $this->getJson('/api/v1/inbox')->assertOk()->assertJsonFragment(['type' => 'cash_handovers', 'app_path' => '/cash']);
        $this->getJson('/api/v1/cash')->assertOk()
            ->assertJsonPath('data.to_receive.0.uuid', $remittance->uuid)
            ->assertJsonPath('data.to_receive.0.can_receive', true);

        $momo = collect($this->getJson("/api/v1/cash/remittances/{$remittance->uuid}")->assertOk()->json('data.lines'))->firstWhere('method', 'mtn_momo');

        $this->postJson("/api/v1/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '5000', 'confirmed_lines' => [$momo['id']]])
            ->assertOk()
            ->assertJsonPath('message', 'Received into your till.');

        $this->assertSame('received', $remittance->refresh()->status);
        $this->assertSame('5000.00', $this->cashBook->balance($this->cashBook->tillFor($cashier)));
        $this->assertSame('5000.00', $this->cashBook->balance($this->cashBook->walletFor('mtn_momo')));

        // Received once: never twice.
        $this->postJson("/api/v1/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '5000'])->assertForbidden();
    }

    public function test_a_short_handover_is_written_off_by_a_manager_never_by_the_cashier(): void
    {
        $remittance = $this->handOver();

        $this->phone('patience@shalomtech.dev');
        $this->postJson("/api/v1/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '4000'])
            ->assertOk()
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Received — 1 000 FCFA short.'));
        $this->postJson("/api/v1/cash/remittances/{$remittance->uuid}/shortage", ['outcome' => 'written_off', 'note' => 'Lost'])->assertForbidden();

        $this->phone('terence@shalomtech.dev');
        $shortage = collect($this->getJson('/api/v1/cash')->assertOk()->json('data.shortages'))->firstWhere('uuid', $remittance->uuid);
        $this->assertSame(['remittance', '1000.00', true], [$shortage['type'], $shortage['amount'], $shortage['can_write_off']]);

        $this->postJson("/api/v1/cash/remittances/{$remittance->uuid}/shortage", ['outcome' => 'written_off'])->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/cash/remittances/{$remittance->uuid}/shortage", ['outcome' => 'written_off', 'note' => 'Robbed — police report 12'])->assertOk();
        $this->assertSame('written_off', $remittance->refresh()->shortage_status);
    }

    public function test_the_cashier_closes_the_day_from_the_phone(): void
    {
        $till = $this->tillWith('patience@shalomtech.dev', '5000');

        $this->phone('patience@shalomtech.dev');
        $this->getJson('/api/v1/cash')->assertOk()
            ->assertJsonPath('data.my_till.uuid', $till->uuid)
            ->assertJsonPath('data.my_till.balance', '5000.00')
            ->assertJsonPath('data.my_till.can_close', true);

        $this->postJson("/api/v1/cash/accounts/{$till->uuid}/close-day", ['counted' => '4500'])
            ->assertOk()
            ->assertJsonPath('message', 'Day closed with a difference of -500 FCFA. It has been recorded.');

        $this->assertTrue($this->cashBook->isDayClosed($till));
        $this->assertSame('4500.00', $this->cashBook->balance($till));
    }

    public function test_money_handed_to_someone_rings_them_and_is_accepted_from_the_phone(): void
    {
        $from = $this->tillWith('patience@shalomtech.dev', '10000');
        $to = $this->cashBook->tillFor($this->user('kelvin@shalomtech.dev'));

        $this->actingAs($this->user('patience@shalomtech.dev'));
        app()->forgetInstance(TenantContext::class);
        $this->post('/cash/transfers', ['from_account' => $from->uuid, 'to_account' => $to->uuid, 'amount' => 8000])->assertSessionHasNoErrors();
        $handover = CashTransfer::query()->latest('id')->firstOrFail();

        $this->assertSame([$this->user('kelvin@shalomtech.dev')->id], Notification::query()->where('type', 'cash.transfer')->where('source_uuid', $handover->uuid)->pluck('recipient_user_id')->map(fn ($id) => (int) $id)->all());

        // The sender sees it only to take it back.
        $this->phone('patience@shalomtech.dev');
        $this->getJson('/api/v1/cash')->assertJsonPath('data.transfers.0.can_recall', true)->assertJsonPath('data.transfers.0.can_accept', false);

        $this->phone('kelvin@shalomtech.dev');
        $this->getJson('/api/v1/inbox')->assertJsonFragment(['type' => 'cash_transfers', 'app_path' => '/cash']);
        $this->getJson('/api/v1/cash')->assertJsonPath('data.transfers.0.uuid', $handover->uuid)->assertJsonPath('data.transfers.0.can_accept', true);

        $this->postJson("/api/v1/cash/transfers/{$handover->uuid}/accept", ['counted' => '9000'])->assertJsonValidationErrors('counted');
        $this->postJson("/api/v1/cash/transfers/{$handover->uuid}/accept", ['counted' => '7500'])->assertOk();

        $this->assertSame('7500.00', $this->cashBook->balance($to));
        $this->assertSame('500.00', (string) CashShortage::query()->open()->where('source_id', $handover->id)->sole()->amount);
    }

    public function test_a_deposit_alerts_the_checkers_who_confirm_it_from_the_phone(): void
    {
        $till = $this->tillWith('patience@shalomtech.dev', '10000');
        $bank = $this->cashBook->createAccount(CashAccount::KIND_BANK, 'Afriland main', null, '001');

        $this->actingAs($this->user('patience@shalomtech.dev'));
        app()->forgetInstance(TenantContext::class);
        $this->post('/cash/transfers', ['from_account' => $till->uuid, 'to_account' => $bank->uuid, 'amount' => 4000, 'reference' => 'SLIP-9', 'slip_photo' => UploadedFile::fake()->image('slip.jpg')])->assertSessionHasNoErrors();
        $deposit = CashTransfer::query()->latest('id')->firstOrFail();

        $alerted = Notification::query()->where('type', 'cash.deposit')->where('source_uuid', $deposit->uuid)->pluck('recipient_user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->user('terence@shalomtech.dev')->id, $alerted);
        $this->assertNotContains($this->user('patience@shalomtech.dev')->id, $alerted);

        $this->phone('terence@shalomtech.dev');
        $this->getJson('/api/v1/cash')->assertJsonPath('data.transfers.0.can_confirm', true);
        $this->postJson("/api/v1/cash/transfers/{$deposit->uuid}/confirm")->assertOk();

        // Confirmed once by this person: no longer theirs to confirm.
        $this->getJson('/api/v1/cash')->assertJsonPath('data.transfers.0.can_confirm', false);
    }

    public function test_only_people_with_a_till_or_the_cash_book_open_it(): void
    {
        $this->phone('divine@shalomtech.dev');
        $this->getJson('/api/v1/cash')->assertForbidden();

        $this->phone('patience@shalomtech.dev');
        $this->getJson('/api/v1/cash')->assertOk()->assertJsonPath('data.can.receive', true)->assertJsonPath('data.alerts', []);
    }
}
