<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Services\CustomerManuscriptRecalculationService;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * manuscript:convert-legacy-prepaid (owner, 2026-10-26): an old-system
 * "covered until" date becomes a number of prepaid months — only the month
 * counts — and the customer is billed exactly as the date would have.
 */
class ConvertLegacyPrepaidTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->travelTo(Carbon::parse('2026-10-26 10:00', 'Africa/Lagos'));
    }

    public function test_dates_become_months_and_bill_the_same(): void
    {
        $covered = $this->customerCoveredUntil('2027-01-29');
        $ending = $this->customerCoveredUntil('2026-10-29');

        $this->artisan('manuscript:convert-legacy-prepaid', ['--tenant' => 'swecom'])->assertSuccessful();
        $this->assertSame('2027-01-29', $this->october($covered)->payment_expiration?->toDateString(), 'A dry run saves nothing.');

        $this->artisan('manuscript:convert-legacy-prepaid', ['--tenant' => 'swecom', '--apply' => true])->assertSuccessful();

        $october = $this->october($covered);
        $this->assertNull($october->payment_expiration, 'No date left.');
        $this->assertSame(3, (int) $october->prepaid_months_remaining, 'Nov, Dec, Jan.');
        $this->assertSame('2500.00', (string) $october->prepaid_rate);
        $this->assertNull($this->october($ending)->payment_expiration);
        $this->assertSame(0, (int) $this->october($ending)->prepaid_months_remaining, 'October was the last covered month.');

        // Billed exactly as the date would have: Nov–Jan covered, February billed.
        foreach (['2026-11' => 0.0, '2026-12' => 0.0, '2027-01' => 0.0] as $period => $due) {
            $this->assertSame($due, (float) $this->bill($covered, $period)->total_bill, "{$period} is covered");
        }
        $this->assertGreaterThan(0, (float) $this->bill($covered, '2027-02')->total_bill, 'billed from February');
        $this->assertGreaterThan(0, (float) $this->bill($ending, '2026-11')->total_bill, 'billed from November');
    }

    private function customerCoveredUntil(string $date): Customer
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'others' => 0, 'status' => 'active']);
        ManuscriptFactory::new()->forPeriod('2026-10')->create([
            'customer_id' => $customer->id, 'bill' => 2500, 'total_arrears' => 0, 'credit' => 0, 'total_bill' => 0,
            'payment_expiration' => $date,
        ]);

        return $customer;
    }

    private function october(Customer $customer): Manuscript
    {
        return Manuscript::query()->where('customer_id', $customer->id)->where('period', '2026-10')->firstOrFail();
    }

    private function bill(Customer $customer, string $period): Manuscript
    {
        return app(CustomerManuscriptRecalculationService::class)->recalculateOne($customer->fresh(), $period, trigger: 'test');
    }
}
