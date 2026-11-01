<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use App\Services\BillNotificationService;
use App\Services\ManuscriptCalculationResult;
use App\Services\ManuscriptCalculator;
use App\Services\ManuscriptService;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * VIP customers are not billed unless "Bill this VIP" is ticked (owner,
 * 2026-11-01): the monthly run freezes them like a passive customer, and
 * they get no bill slip, reminder or disconnection flag. Runs against a
 * disposable tenant schema, like FreeMonthsBillingTest.
 */
class VipBillingTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('zvip');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return CustomerFactory::new()->create([
            'zone_id' => ZoneFactory::new()->create()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
            'level' => 'normal',
            ...$attributes,
        ])->fresh();
    }

    private function calculate(Customer $customer, ?Manuscript $previous = null, ?Collection $payments = null): ManuscriptCalculationResult
    {
        return (new ManuscriptCalculator)->calculate($customer, '2026-11', $previous, $payments ?? new Collection, new Collection);
    }

    public function test_a_new_vip_is_not_billed_by_default(): void
    {
        $attributes = CustomerFactory::new()->make(['zone_id' => ZoneFactory::new()->create()->id, 'level' => 'Vip'])->getAttributes();
        unset($attributes['vip_billed']);

        $customer = Customer::query()->create($attributes)->fresh();

        $this->assertFalse($customer->vip_billed);
        $this->assertTrue($customer->isExcludedFromBilling());
    }

    public function test_an_unbilled_vip_is_frozen_with_no_new_charge_and_keeps_old_debt(): void
    {
        $customer = $this->customer(['level' => 'Vip', 'vip_billed' => false, 'others' => 5000]);
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500, 'verification_status' => 'verified']);

        $result = $this->calculate($customer, payments: new Collection([$payment]));

        $this->assertTrue($result->isFrozen);
        $this->assertSame('not_billed', $result->frozenReason);
        $this->assertSame('0.00', $result->totalBill);
        $this->assertSame('5000.00', $result->totalArrears);
        $this->assertSame('0.00', $result->income);
        $this->assertCount(0, $result->processedPayments);
    }

    public function test_a_vip_with_bill_this_vip_ticked_is_billed_like_anyone(): void
    {
        $result = $this->calculate($this->customer(['level' => 'Vip', 'vip_billed' => true]));

        $this->assertFalse($result->isFrozen);
        $this->assertSame($this->ordinaryTotalBill(), $result->totalBill);
    }

    /** What the same month bills an ordinary customer with the same bill. */
    private function ordinaryTotalBill(): string
    {
        return $this->calculate($this->customer())->totalBill;
    }

    public function test_the_flag_means_nothing_for_normal_and_operator_customers(): void
    {
        foreach (['normal', 'Operator'] as $level) {
            $customer = $this->customer(['level' => $level, 'vip_billed' => false]);

            $this->assertFalse($customer->isExcludedFromBilling());
            $this->assertSame($this->ordinaryTotalBill(), $this->calculate($customer)->totalBill);
        }
    }

    public function test_the_billed_scope_leaves_out_only_unbilled_vips(): void
    {
        $normal = $this->customer();
        $billedVip = $this->customer(['level' => 'Vip', 'vip_billed' => true]);
        $unbilledVip = $this->customer(['level' => 'Vip', 'vip_billed' => false]);

        $ids = Customer::query()->billed()->pluck('id');

        $this->assertTrue($ids->contains($normal->id));
        $this->assertTrue($ids->contains($billedVip->id));
        $this->assertFalse($ids->contains($unbilledVip->id));
    }

    public function test_an_unbilled_vip_gets_no_bill_reminder_and_no_printed_bill(): void
    {
        $customer = $this->customer(['level' => 'Vip', 'vip_billed' => false]);
        Manuscript::query()->firstOrNew(['customer_id' => $customer->id, 'period' => '2026-11'])->fill($this->calculate($customer)->toManuscriptAttributes())->save();

        $this->assertNull(app(BillNotificationService::class)->composeMessage($customer->fresh()));

        $this->expectException(ValidationException::class);
        app(ManuscriptService::class)->billData($customer->fresh(), '2026-11');
    }

    public function test_switching_a_vip_to_billed_bills_them_from_the_next_run(): void
    {
        $customer = $this->customer(['level' => 'Vip', 'vip_billed' => false]);
        $customer->update(['vip_billed' => true]);

        $this->assertSame($this->ordinaryTotalBill(), $this->calculate($customer->fresh())->totalBill);
    }
}
