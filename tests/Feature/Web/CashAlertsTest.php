<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Mail\CashAlertsDigestMail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Payment;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Models\Remittance;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CashAlertService;
use App\Services\ReceiptStockService;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Cash controls wave 6 (docs/plans/cash-controls.md): the live alerts —
 * each appears from the records themselves and disappears once its cause
 * is dealt with.
 */
class CashAlertsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        // Cash controls "went live" a month ago, so backdated test payments count.
        Company::query()->update(['cash_controls_start_date' => now()->subMonth()->toDateString(), 'remittance_deadline_hours' => 24]);
        Cache::forget(Company::CACHE_KEY);
    }

    private function as(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, array<string, mixed>> */
    private function alerts(): array
    {
        return collect(app(CashAlertService::class)->alerts())->keyBy('key')->all();
    }

    private function recordCash(string $amount = '2500', string $method = 'cash', ?string $reference = null): Payment
    {
        $this->as('kelvin@shalomtech.dev', 'manager');
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);

        $this->post('/payments', array_filter(['customer_uuid' => $customer->uuid, 'amount' => $amount, 'frequency' => 'monthly', 'method' => $method, 'method_reference' => $reference]))
            ->assertSessionHasNoErrors();

        return Payment::query()->where('customer_id', $customer->id)->sole();
    }

    public function test_cash_held_past_the_deadline_is_flagged_until_handed_over(): void
    {
        $payment = $this->recordCash('7500');
        $this->assertArrayNotHasKey('cash_not_handed_over', $this->alerts());

        $payment->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();

        $alert = $this->alerts()['cash_not_handed_over'];
        $this->assertSame('7500.00', $alert['items'][0]['amount']);

        $this->post('/cash/remittances', ['declared_cash' => '7500'])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('cash_not_handed_over', $this->alerts());
    }

    public function test_shortages_and_missing_wallet_payments_are_flagged(): void
    {
        $this->recordCash('5000');
        $this->recordCash('3000', 'orange_money', 'OM-1');
        $this->post('/cash/remittances', ['declared_cash' => '5000']);
        $remittance = Remittance::query()->latest('id')->firstOrFail();

        $this->as('patience@shalomtech.dev', 'cashier');
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '4000', 'confirmed_lines' => []])->assertSessionHasNoErrors();

        $alerts = $this->alerts();
        $this->assertSame('1000.00', $alerts['open_shortages']['items'][0]['amount']);
        $this->assertStringContainsString('OM-1', $alerts['wallet_not_found']['items'][0]['label']);
    }

    public function test_receipt_numbers_scanned_but_never_recorded_and_unaccounted_ones(): void
    {
        $holder = $this->as('terence@shalomtech.dev', 'manager');
        $book = app(ReceiptStockService::class)->allocate(ReceiptBlock::KIND_BOOK, $holder, 25);

        ReceiptLeaf::query()->where('number', $book->firstNumber())->update(['scanned_unused_at' => now()->subHours(48)]);
        app(ReceiptStockService::class)->closeBlock($book, $holder);

        $alerts = $this->alerts();
        $this->assertStringContainsString($book->firstNumber(), $alerts['scanned_unrecorded']['items'][0]['label']);
        $this->assertStringContainsString('25 number(s)', $alerts['unaccounted_numbers']['items'][0]['detail']);
    }

    public function test_an_overdue_advance_is_flagged(): void
    {
        $requester = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        Voucher::query()->create([
            'number' => 'BC-TEST-000001',
            'kind' => Voucher::KIND_ADVANCE,
            'payee_name' => 'Kelvin',
            'purpose' => 'Cable for Kumba Town',
            'amount' => 15000,
            'needed_on' => now()->subDays(10)->toDateString(),
            'status' => 'paid',
            'requested_by' => $requester->id,
            'branch_id' => Branch::headquartersId(),
            'paid_at' => now()->subDays(10),
            'settle_due_on' => now()->subDays(3)->toDateString(),
            'signed_copy_path' => 'vouchers/x.jpg',
        ]);

        $this->assertSame('15000.00', $this->alerts()['advances_overdue']['items'][0]['amount']);
    }

    public function test_the_alerts_page_and_digest(): void
    {
        $payment = $this->recordCash('7500');
        $payment->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/cash/alerts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Cash/Alerts')
            ->where('alerts.0.key', 'cash_not_handed_over'));

        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->get('/cash/alerts')->assertForbidden();

        $mail = new CashAlertsDigestMail(app(CashAlertService::class)->alerts(), 'Terence');
        $this->assertStringContainsString('Cash not handed over', $mail->render());
    }
}
