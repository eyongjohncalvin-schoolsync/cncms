<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\FreeMonthGrant;
use App\Models\InstallationOffer;
use App\Models\Service;
use Database\Factories\InstallationOfferFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The mobile app's online add-customer with installation and first payment
 * (docs/plans/installation-and-promotions.md, phase 2, item 5), and the
 * promo state the sync pull carries (item 8).
 */
class CustomerInstallationApiTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Service $tv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->tv = Service::query()->where('slug', 'tv')->firstOrFail();
        $this->tv->update(['price' => 2500]);
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
    }

    private function offer(): InstallationOffer
    {
        $offer = InstallationOfferFactory::new()->create(['fee' => 10000, 'is_default' => true]);
        $offer->serviceMonths()->create(['service_id' => $this->tv->id, 'free_months' => 2]);

        return $offer;
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $extra = []): array
    {
        return [
            'zone_uuid' => ZoneFactory::new()->create()->uuid,
            'name' => 'Mobile Installation',
            'phone' => '699112233',
            'services' => [['service_uuid' => $this->tv->uuid, 'price' => '2500']],
            ...$extra,
        ];
    }

    public function test_the_app_gets_the_offers(): void
    {
        $offer = $this->offer();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('manager'))
            ->getJson('/api/v1/customers/installation-options')
            ->assertOk()
            ->assertJsonPath('data.offers.0.uuid', $offer->uuid)
            ->assertJsonPath('data.offers.0.months.0.months', 2)
            ->assertJsonPath('data.can_manage', true);
    }

    public function test_the_app_previews_and_saves_a_customer_with_installation_and_advance(): void
    {
        $offer = $this->offer();
        $token = $this->tokenForRole('manager');
        $form = $this->form(['installation_offer_uuid' => $offer->uuid, 'installation_paid' => '10000', 'method' => 'cash', 'service_plans' => [['service_uuid' => $this->tv->uuid, 'free_from' => '2026-10', 'free_months' => 2, 'paid_months' => 3]]]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customers/installation-preview', $form)
            ->assertOk()
            ->assertJsonPath('data.collect_now', '17500.00')
            ->assertJsonPath('data.first_bill', '2027-03');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/customers', $form)->assertCreated();

        $response->assertJsonPath('data.billing_starts_period', '2026-10')
            ->assertJsonPath('data.coverage.first_bill', '2027-03')
            ->assertJsonCount(2, 'payments');
        $this->assertNotNull($response->json('payments.1.receipt_number'));

        $customer = Customer::query()->where('uuid', $response->json('data.uuid'))->sole();
        $this->assertSame('2026-10', FreeMonthGrant::query()->where('customer_id', $customer->id)->value('start_period'));
    }

    public function test_an_older_app_build_still_adds_a_plain_customer(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('manager'))
            ->postJson('/api/v1/customers', $this->form())
            ->assertCreated()
            ->assertJsonCount(0, 'payments');

        $this->assertSame('2026-10', Customer::query()->where('uuid', $response->json('data.uuid'))->value('billing_starts_period'));
    }

    public function test_the_sync_pull_carries_the_promo_state(): void
    {
        $offer = $this->offer();
        $token = $this->tokenForRole('manager');
        $uuid = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customers', $this->form(['installation_offer_uuid' => $offer->uuid, 'installation_paid' => '10000', 'method' => 'cash', 'service_plans' => [['service_uuid' => $this->tv->uuid, 'free_from' => '2026-10', 'free_months' => 2, 'paid_months' => 0]]]))
            ->assertCreated()
            ->json('data.uuid');

        $row = collect($this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull')->assertOk()->json('changes.customers.upserted'))
            ->firstWhere('uuid', $uuid);

        $this->assertSame('2026-10', $row['billing_starts_period']);
        $this->assertSame([['service' => $this->tv->name, 'from' => '2026-10', 'until' => '2026-11']], $row['free_months']);
    }
}
