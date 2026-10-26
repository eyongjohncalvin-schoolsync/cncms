<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Bill money and installation money are kept apart on every screen (owner,
 * 2026-10-26): one payments table — one cash trail, verified, receipted and
 * handed over alike — but never one total, and never mixed in one list.
 */
class BillAndInstallationMoneyTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Customer $customer;

    private Payment $bill;

    private Payment $installation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'admin', 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        $this->customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'others' => 0, 'status' => 'active']);

        // A 2,500 monthly bill and a 10,000 installation fee, both verified.
        $this->post('/payments', ['customer_uuid' => $this->customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->post("/customers/{$this->customer->uuid}/installation-charges", ['amount' => '10000', 'reason' => 'New connection'])->assertSessionHasNoErrors();
        $charge = CustomerCharge::query()->where('customer_id', $this->customer->id)->sole();
        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 10000, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->bill = Payment::query()->where('customer_id', $this->customer->id)->subscription()->sole();
        $this->installation = Payment::query()->where('customer_charge_id', $charge->id)->sole();
    }

    public function test_the_payments_page_shows_monthly_bills_unless_asked_for_installation_fees(): void
    {
        $uuids = fn (string $url): array => collect($this->get($url)->assertOk()->viewData('page')['props']['payments']['data'])->pluck('uuid')->all();

        $this->assertContains($this->bill->uuid, $uuids('/payments'));
        $this->assertNotContains($this->installation->uuid, $uuids('/payments'), 'Installation money is not in the bills list.');

        $this->assertSame([$this->installation->uuid], array_values(array_intersect($uuids('/payments?purpose=fees'), [$this->bill->uuid, $this->installation->uuid])));
        $this->assertContains($this->installation->uuid, $uuids('/payments?purpose=all'));

        $this->get('/payments?purpose=fees')->assertInertia(fn (Assert $page) => $page
            ->where('filters.purpose', 'fees')
            ->where('payments.data', fn ($rows) => collect($rows)->firstWhere('uuid', $this->installation->uuid)['purpose_label'] === 'Installation'));
    }

    public function test_rejected_payments_only_show_under_rejected_and_can_be_deleted_unless_receipted(): void
    {
        $rejected = PaymentFactory::new()->pending()->create(['customer_id' => $this->customer->id, 'amount' => 3000]);
        $this->post("/payments/{$rejected->uuid}/verify", ['action' => 'reject', 'notes' => 'Wrong customer'])->assertSessionHasNoErrors();

        $uuids = fn (string $url): array => collect($this->get($url)->assertOk()->viewData('page')['props']['payments']['data'])->pluck('uuid')->all();
        $this->assertNotContains($rejected->uuid, $uuids('/payments'), 'Not money: not in the default list.');
        $this->assertContains($rejected->uuid, $uuids('/payments?verification_status=rejected'));

        $this->delete("/payments/{$rejected->uuid}")->assertSessionHasNoErrors();
        $this->assertNull(Payment::query()->find($rejected->id));

        // A reversed payment keeps its VOID receipt as proof.
        $this->bill->update(['recorded_by' => null]);
        $this->post("/payments/{$this->bill->uuid}/reverse", ['reason' => 'Paid twice'])->assertSessionHasNoErrors();
        $this->delete("/payments/{$this->bill->uuid}")->assertSessionHasErrors('payment');
        Cache::flush(); // the list is cached for 25 seconds (PaymentService::list())
        $this->assertNotContains($this->bill->uuid, $uuids('/payments'));
    }

    public function test_the_dashboard_shows_bill_money_and_installation_money_apart(): void
    {
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.income_by_purpose.subscription', fn ($value) => bccomp((string) $value, '2500', 2) >= 0)
            ->where('stats.income_by_purpose.installation', fn ($value) => bccomp((string) $value, '10000', 2) >= 0));
    }
}
