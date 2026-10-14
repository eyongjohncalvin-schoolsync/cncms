<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\PaymentReceipt;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\PaymentReceiptService;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The "To send" page (SendQueueController / SendQueueService): receipts and
 * this month's bills still waiting to reach the customer, and how rows
 * leave the list (sent, printed, or skipped).
 */
class SendQueueTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake('local');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function customer(array $overrides = []): Customer
    {
        return CustomerFactory::new()->create([
            'zone_id' => ZoneFactory::new()->create()->id,
            'bill' => 2500,
            'status' => 'active',
            'phone' => '677001122',
            ...$overrides,
        ]);
    }

    private function receiptFor(Customer $customer): PaymentReceipt
    {
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500, 'verification_status' => 'verified']);

        return app(PaymentReceiptService::class)->issueFor($payment, autoSend: false);
    }

    /**
     * @return list<string>
     */
    private function listed(string $tab, string $key = 'uuid'): array
    {
        $uuids = [];

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15] as $page) {
            $response = $this->get("/to-send?tab={$tab}&{$tab}_page={$page}")->assertOk();
            $props = $response->viewData('page')['props'][$tab];
            $uuids = [...$uuids, ...array_column($props['data'], $key)];

            if ($page >= $props['meta']['last_page']) {
                break;
            }
        }

        return $uuids;
    }

    public function test_an_unsent_receipt_is_listed_and_leaves_once_sent_on_whatsapp(): void
    {
        $this->actingAsRole('manager');
        $receipt = $this->receiptFor($this->customer());

        $this->assertContains($receipt->uuid, $this->listed('receipts'));

        $this->post("/payments/{$receipt->payment->uuid}/receipt/send-whatsapp")->assertSessionHas('whatsapp_url');

        $this->assertNotContains($receipt->uuid, $this->listed('receipts'));
    }

    public function test_skip_removes_a_receipt_and_void_or_old_receipts_never_appear(): void
    {
        $this->actingAsRole('manager');
        $skipped = $this->receiptFor($this->customer());
        $voided = $this->receiptFor($this->customer());
        $voided->update(['status' => PaymentReceipt::STATUS_VOID]);
        $old = $this->receiptFor($this->customer());
        $old->forceFill(['issued_at' => now()->subDays(90)])->save();

        $this->post("/to-send/receipts/{$skipped->uuid}/skip")->assertSessionHas('success');

        $listed = $this->listed('receipts');
        $this->assertNotContains($skipped->uuid, $listed);
        $this->assertNotContains($voided->uuid, $listed);
        $this->assertNotContains($old->uuid, $listed);
        $this->assertSame('skipped', $skipped->fresh()->sent_log[0]['channel']);
    }

    public function test_a_printed_receipt_customer_stays_listed_until_the_receipt_is_printed(): void
    {
        $this->actingAsRole('manager');
        $receipt = $this->receiptFor($this->customer(['preferred_channel' => 'print']));

        $this->get('/to-send?tab=receipts')->assertInertia(fn (Assert $page) => $page
            ->where('receipts.data', fn ($rows) => collect($rows)->firstWhere('uuid', $receipt->uuid)['channel'] === 'print'));

        $this->get(route('payment-receipts.pdf', $receipt).'?format=thermal58')->assertOk();

        $this->assertNotContains($receipt->uuid, $this->listed('receipts'));
    }

    public function test_a_customer_who_prefers_email_but_has_none_falls_back_to_whatsapp(): void
    {
        $this->actingAsRole('manager');
        $receipt = $this->receiptFor($this->customer(['preferred_channel' => 'email', 'email' => null]));

        $this->get('/to-send?tab=receipts')->assertInertia(fn (Assert $page) => $page
            ->where('receipts.data', fn ($rows) => collect($rows)->firstWhere('uuid', $receipt->uuid)['channel'] === 'whatsapp'));
    }

    public function test_bills_list_this_months_bills_for_active_customers_with_something_to_pay(): void
    {
        $this->actingAsRole('manager');
        $period = now()->format('Y-m');
        $bill = fn (Customer $customer, int $totalBill): Manuscript => ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'total_bill' => $totalBill]);

        $owes = $bill($this->customer(), 5000);
        $nothingDue = $bill($this->customer(), 0);
        $disconnected = $bill($this->customer(['status' => 'disconnected']), 5000);

        $listed = $this->listed('bills');
        $this->assertContains($owes->uuid, $listed);
        $this->assertNotContains($nothingDue->uuid, $listed);
        $this->assertNotContains($disconnected->uuid, $listed);
    }

    public function test_a_bill_leaves_the_list_once_sent_or_skipped(): void
    {
        $this->actingAsRole('manager');
        $period = now()->format('Y-m');
        $sent = ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $this->customer()->id, 'total_bill' => 5000]);
        $skipped = ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $this->customer()->id, 'total_bill' => 5000]);

        $this->post("/manuscripts/{$sent->customer->uuid}/send-bill")->assertSessionHas('success');
        $this->post("/to-send/bills/{$skipped->uuid}/skip")->assertSessionHas('success');

        $listed = $this->listed('bills');
        $this->assertNotContains($sent->uuid, $listed);
        $this->assertNotContains($skipped->uuid, $listed);
        $this->assertSame('whatsapp_manual', $sent->fresh()->sent_log[0]['channel']);
    }

    public function test_a_worker_sees_receipts_but_not_bills(): void
    {
        $this->actingAsRole('worker');

        $this->get('/to-send?tab=bills')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'receipts')
            ->where('can.bills', false)
            ->where('bills', null));

        $this->post('/to-send/bills/'.ManuscriptFactory::new()->create()->uuid.'/skip')->assertForbidden();
    }
}
