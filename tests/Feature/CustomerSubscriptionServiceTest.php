<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataTransferObjects\CustomerData;
use App\DataTransferObjects\CustomerServiceSelection;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Services\CustomerService;
use App\Services\CustomerSubscriptionService;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * services.md sections 4 & 5 — CustomerSubscriptionService is the single
 * writer of the `customer_service` pivot and, through it, of the cached
 * `customers.bill` projection. Covers both plain services and variants
 * (priced sub-options one level deep, e.g. a TV channel broadcast) and the
 * invariant that a variant requires its parent service also selected.
 *
 * Runs against the real `tenantswecom` schema (InteractsWithTenantRoles),
 * rolled back with the outer transaction like every other test using that
 * trait — the four seeded services (tv/internet/vod/satellite-hosting) are
 * real fixtures here, not something this test creates.
 */
class CustomerSubscriptionServiceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function customer(float $bill = 1000): Customer
    {
        $zone = ZoneFactory::new()->create();

        return CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => $bill, 'status' => 'active']);
    }

    private function tvService(): Service
    {
        return Service::query()->where('slug', 'tv')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Base services — attach / reprice / detach, bill recomputed
    // -----------------------------------------------------------------

    public function test_sync_attaches_two_services_and_sums_the_bill(): void
    {
        $customer = $this->customer();
        $tv = $this->tvService();
        $internet = Service::query()->where('slug', 'internet')->firstOrFail();

        app(CustomerSubscriptionService::class)->sync($customer, [
            new CustomerServiceSelection($tv->uuid, '5000'),
            new CustomerServiceSelection($internet->uuid, '3000'),
        ]);

        $customer->refresh();

        $this->assertSame('8000.00', (string) $customer->bill);
        $this->assertSame(2, $customer->subscriptions()->count());
    }

    public function test_sync_detaches_a_service_removed_from_the_selection(): void
    {
        $customer = $this->customer();
        $tv = $this->tvService();
        $internet = Service::query()->where('slug', 'internet')->firstOrFail();
        $service = app(CustomerSubscriptionService::class);

        $service->sync($customer, [
            new CustomerServiceSelection($tv->uuid, '5000'),
            new CustomerServiceSelection($internet->uuid, '3000'),
        ]);

        $service->sync($customer, [
            new CustomerServiceSelection($tv->uuid, '5000'),
        ]);

        $customer->refresh();

        $this->assertSame('5000.00', (string) $customer->bill);
        $this->assertSame(1, $customer->subscriptions()->count());
    }

    public function test_sync_rejects_an_empty_selection(): void
    {
        $customer = $this->customer();

        $this->expectException(ValidationException::class);

        app(CustomerSubscriptionService::class)->sync($customer, []);
    }

    public function test_sync_rejects_the_same_service_selected_twice(): void
    {
        $customer = $this->customer();
        $tv = $this->tvService();

        $this->expectException(ValidationException::class);

        app(CustomerSubscriptionService::class)->sync($customer, [
            new CustomerServiceSelection($tv->uuid, '5000'),
            new CustomerServiceSelection($tv->uuid, '6000'),
        ]);
    }

    // -----------------------------------------------------------------
    // Legacy `bill`-only callers (StoreCustomerRequest/CustomerImportService
    // haven't been migrated to send `services` yet — services.md section 6)
    // -----------------------------------------------------------------

    /**
     * Regression: CustomerService::create() unconditionally recomputes
     * `bill` from the subscription it attaches. Before defaultSelection()
     * accepted a $bill override, a caller sending only the legacy raw
     * `bill` field (no `services` key at all) had it silently reset to the
     * default service's 0.00 catalogue seed price the instant the
     * subscription sync ran — this is exactly the bug that fix closes.
     */
    public function test_create_with_no_services_key_preserves_the_legacy_bill_value(): void
    {
        $customer = app(CustomerService::class)->create(new CustomerData(
            zoneUuid: ZoneFactory::new()->create()->uuid,
            name: 'Legacy Caller Customer',
            bill: '2500',
            phone: '677000111',
        ));

        $this->assertSame('2500.00', (string) $customer->fresh()->bill);
        $this->assertSame(1, $customer->subscriptions()->count());
    }

    // -----------------------------------------------------------------
    // Variants (services.md section 4)
    // -----------------------------------------------------------------

    public function test_sync_attaches_a_variant_alongside_its_base_service(): void
    {
        $customer = $this->customer();
        $tv = $this->tvService();
        $channel = ServiceVariant::query()->create([
            'service_id' => $tv->id,
            'name' => 'Local News Channel',
            'price' => 2000,
        ]);

        app(CustomerSubscriptionService::class)->sync($customer, [
            new CustomerServiceSelection($tv->uuid, '5000'),
            new CustomerServiceSelection($tv->uuid, '2000', $channel->uuid),
        ]);

        $customer->refresh();

        $this->assertSame('7000.00', (string) $customer->bill);

        $rows = $customer->subscriptions()->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains(fn (CustomerSubscription $r) => $r->service_variant_id === null && (string) $r->price === '5000.00'));
        $this->assertTrue($rows->contains(fn (CustomerSubscription $r) => $r->service_variant_id === $channel->id && (string) $r->price === '2000.00'));
    }

    public function test_sync_rejects_a_variant_selected_without_its_base_service(): void
    {
        $customer = $this->customer();
        $tv = $this->tvService();
        $channel = ServiceVariant::query()->create([
            'service_id' => $tv->id,
            'name' => 'Local News Channel',
            'price' => 2000,
        ]);

        $this->expectException(ValidationException::class);

        // No plain TV selection in this set — only the variant.
        app(CustomerSubscriptionService::class)->sync($customer, [
            new CustomerServiceSelection($tv->uuid, '2000', $channel->uuid),
        ]);
    }

    public function test_untick_the_base_service_detaches_its_variant_too(): void
    {
        $customer = $this->customer();
        $tv = $this->tvService();
        $internet = Service::query()->where('slug', 'internet')->firstOrFail();
        $channel = ServiceVariant::query()->create([
            'service_id' => $tv->id,
            'name' => 'Local News Channel',
            'price' => 2000,
        ]);
        $subscriptions = app(CustomerSubscriptionService::class);

        $subscriptions->sync($customer, [
            new CustomerServiceSelection($tv->uuid, '5000'),
            new CustomerServiceSelection($tv->uuid, '2000', $channel->uuid),
        ]);

        // Drop TV (and, necessarily, its channel) in favor of Internet alone.
        $subscriptions->sync($customer, [
            new CustomerServiceSelection($internet->uuid, '3000'),
        ]);

        $customer->refresh();

        $this->assertSame('3000.00', (string) $customer->bill);
        $this->assertSame(1, $customer->subscriptions()->count());
    }

    public function test_a_variant_belonging_to_a_different_service_is_rejected(): void
    {
        $customer = $this->customer();
        $tv = $this->tvService();
        $internet = Service::query()->where('slug', 'internet')->firstOrFail();
        $internetVariant = ServiceVariant::query()->create([
            'service_id' => $internet->id,
            'name' => '20 Mbps',
            'price' => 4000,
        ]);

        $this->expectException(ValidationException::class);

        // $internetVariant belongs to Internet, mismatched against $tv here.
        app(CustomerSubscriptionService::class)->sync($customer, [
            new CustomerServiceSelection($tv->uuid, '5000'),
            new CustomerServiceSelection($tv->uuid, '4000', $internetVariant->uuid),
        ]);
    }

    public function test_apply_variant_price_to_subscribers_reprices_every_holder(): void
    {
        $customerA = $this->customer();
        $customerB = $this->customer();
        $tv = $this->tvService();
        $channel = ServiceVariant::query()->create([
            'service_id' => $tv->id,
            'name' => 'Local News Channel',
            'price' => 2000,
        ]);
        $subscriptions = app(CustomerSubscriptionService::class);

        foreach ([$customerA, $customerB] as $customer) {
            $subscriptions->sync($customer, [
                new CustomerServiceSelection($tv->uuid, '5000'),
                new CustomerServiceSelection($tv->uuid, '2000', $channel->uuid),
            ]);
        }

        $channel->update(['price' => 2500]);
        $affected = $subscriptions->applyVariantPriceToSubscribers($channel->fresh());

        $this->assertSame(2, $affected);
        $this->assertSame('7500.00', (string) $customerA->fresh()->bill);
        $this->assertSame('7500.00', (string) $customerB->fresh()->bill);
    }

    public function test_apply_catalogue_price_to_subscribers_never_touches_variant_rows(): void
    {
        $customer = $this->customer();
        $tv = $this->tvService();
        $channel = ServiceVariant::query()->create([
            'service_id' => $tv->id,
            'name' => 'Local News Channel',
            'price' => 2000,
        ]);
        $subscriptions = app(CustomerSubscriptionService::class);

        $subscriptions->sync($customer, [
            new CustomerServiceSelection($tv->uuid, '5000'),
            new CustomerServiceSelection($tv->uuid, '2000', $channel->uuid),
        ]);

        $tv->update(['price' => 6000]);
        $subscriptions->applyCataloguePriceToSubscribers($tv->fresh());

        $customer->refresh();

        // The base row moved to 6000, the variant row's 2000 is untouched.
        $this->assertSame('8000.00', (string) $customer->bill);
    }

    // -----------------------------------------------------------------
    // DB-level defense-in-depth (the service already rejects duplicates
    // before any write, so this proves the constraint independently)
    // -----------------------------------------------------------------

    public function test_the_database_rejects_a_second_base_row_for_the_same_customer_and_service(): void
    {
        $customer = $this->customer();
        $tv = $this->tvService();

        CustomerSubscription::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $tv->id,
            'price' => 5000,
        ]);

        $this->expectException(QueryException::class);

        CustomerSubscription::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $tv->id,
            'price' => 6000,
        ]);
    }
}
