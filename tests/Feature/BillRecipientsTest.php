<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Manuscript;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CustomerManuscriptRecalculationService;
use App\Services\ManuscriptService;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\FreeMonthGrantFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Who gets a bill for the month (owner's question, 2026-10-26): every
 * customer has their month written in the register, but a bill is printed
 * or sent only to someone with something to pay — never to a customer whose
 * month is free, paid ahead, or covered by their credit.
 */
class BillRecipientsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'admin', 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);
        $this->period = BusinessTimezone::currentPeriod();
    }

    public function test_only_customers_with_something_to_pay_get_a_bill(): void
    {
        $zone = ZoneFactory::new()->create();
        $previous = Carbon::createFromFormat('!Y-m', $this->period)->subMonthNoOverflow()->format('Y-m');

        // A month free (compensation), months paid ahead, credit that covers
        // the bill, a credit that covers only part of it, and an ordinary customer.
        $free = $this->subscriber($zone->id);
        FreeMonthGrantFactory::new()->create(['customer_id' => $free->id, 'start_period' => $this->period, 'months' => 2]);

        $prepaid = $this->subscriber($zone->id);
        PaymentFactory::new()->create([
            'customer_id' => $prepaid->id, 'amount' => 7500, 'frequency' => 'months', 'months' => 3,
            'prepaid_rate' => 2500, 'clear_arrears_first' => true, 'expiration_date' => null, 'verification_status' => 'verified',
        ]);

        $credit = $this->subscriber($zone->id);
        ManuscriptFactory::new()->create(['customer_id' => $credit->id, 'period' => $previous, 'bill' => 2500, 'total_arrears' => 0, 'credit' => 5000, 'total_bill' => 0]);

        $partCredit = $this->subscriber($zone->id);
        ManuscriptFactory::new()->create(['customer_id' => $partCredit->id, 'period' => $previous, 'bill' => 2500, 'total_arrears' => 0, 'credit' => 1000, 'total_bill' => 0]);

        $ordinary = $this->subscriber($zone->id);

        foreach ([$free, $prepaid, $credit, $partCredit, $ordinary] as $customer) {
            app(CustomerManuscriptRecalculationService::class)->recalculateOne($customer->fresh(), $this->period, trigger: 'test');
        }

        $this->assertSame('0.00', $this->totalBill($free), 'A free month: nothing to pay.');
        $this->assertSame('0.00', $this->totalBill($prepaid), 'Paid ahead: nothing to pay.');
        $this->assertSame('0.00', $this->totalBill($credit), 'Credit covers the bill.');
        // Something to pay (the amount follows business-rules.md's payment-before-service total).
        $this->assertGreaterThan(0, (float) $this->totalBill($partCredit), 'Credit covers only part of it.');
        $this->assertGreaterThan(0, (float) $this->totalBill($ordinary));

        $recipients = app(ManuscriptService::class)->billRecipients(['period' => $this->period, 'zone_uuid' => $zone->uuid])['customers']->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$partCredit->id, $ordinary->id], $recipients, 'Only those with something to pay get a bill.');
    }

    private function subscriber(int $zoneId): Customer
    {
        $customer = CustomerFactory::new()->create(['zone_id' => $zoneId, 'bill' => 2500, 'others' => 0, 'status' => 'active']);
        CustomerSubscription::query()->create(['customer_id' => $customer->id, 'service_id' => Service::query()->where('slug', 'tv')->value('id'), 'price' => 2500]);

        return $customer;
    }

    private function totalBill(Customer $customer): string
    {
        return (string) Manuscript::query()->where('customer_id', $customer->id)->where('period', $this->period)->value('total_bill');
    }
}
