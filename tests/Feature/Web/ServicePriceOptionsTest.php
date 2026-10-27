<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Role;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Price options (owner, 2026-10-27): a service offers quick-pick prices
 * besides its default and may set a minimum. Anyone may pick the default or
 * an option; only `customers.custom_price` types another amount, and never
 * below the minimum. A customer's existing price stays.
 */
class ServicePriceOptionsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Service $tv;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->tv = Service::query()->where('slug', 'tv')->firstOrFail();
        $this->tv->update(['price' => 2500, 'min_price' => 2000, 'price_options' => [['amount' => '2000.00', 'label' => 'Neighbourhood'], ['amount' => '3000.00', 'label' => 'Business']]]);
        $this->zone = ZoneFactory::new()->create();
    }

    public function test_settings_save_options_cheapest_first_and_refuse_any_below_the_minimum(): void
    {
        $this->as('admin');

        $this->patch("/settings/services/{$this->tv->uuid}", [
            'min_price' => '2000',
            'price_options' => [['amount' => '3000', 'label' => ' Business '], ['amount' => '2000', 'label' => ''], ['amount' => '3000', 'label' => 'Twice']],
        ])->assertSessionHasNoErrors();

        $this->assertEquals([['amount' => '2000.00', 'label' => null], ['amount' => '3000.00', 'label' => 'Business']], $this->tv->fresh()->price_options);

        $this->patch("/settings/services/{$this->tv->uuid}", ['price_options' => [['amount' => '1500']]])->assertSessionHasErrors('price_options');
        $this->patch("/settings/services/{$this->tv->uuid}", ['min_price' => '2600'])->assertSessionHasErrors('min_price');
    }

    public function test_someone_not_allowed_to_type_picks_the_default_or_an_option_only(): void
    {
        $this->asDeskClerk();

        $this->register('DESK DEFAULT', '2500')->assertSessionHasNoErrors();
        $this->register('DESK OPTION', '3000')->assertSessionHasNoErrors();
        $this->register('DESK TYPED', '2700')->assertSessionHasErrors(['services' => "Choose TV Service's price from its options (2,000 / 2,500 / 3,000 FCFA) — only a manager can set another amount."]);

        $this->assertSame(['2500.00', '3000.00'], Customer::query()->whereIn('name', ['DESK DEFAULT', 'DESK OPTION'])->orderBy('name')->pluck('bill')->map(fn ($bill): string => (string) $bill)->all());
        $this->assertFalse(Customer::query()->where('name', 'DESK TYPED')->exists());
    }

    public function test_a_manager_types_any_amount_at_or_above_the_minimum(): void
    {
        $this->as('manager');

        $this->register('MANAGER TYPED', '2700')->assertSessionHasNoErrors();
        $this->register('MANAGER TOO LOW', '1500')->assertSessionHasErrors(['services' => "TV Service can't be below its minimum of 2,000 FCFA."]);
    }

    public function test_a_customers_existing_price_stays_even_below_the_minimum(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => $this->zone->id, 'bill' => 1500, 'status' => 'active']);
        CustomerSubscription::query()->create(['customer_id' => $customer->id, 'service_id' => $this->tv->id, 'price' => 1500]);
        $this->asDeskClerk();

        $this->patch("/customers/{$customer->uuid}", ['name' => 'KEPT OLD PRICE', 'services' => [['service_uuid' => $this->tv->uuid, 'price' => '1500']]])
            ->assertSessionHasNoErrors();
        $this->assertSame('1500.00', (string) $customer->fresh()->bill);

        // …but a new typed price is still refused.
        $this->patch("/customers/{$customer->uuid}", ['services' => [['service_uuid' => $this->tv->uuid, 'price' => '1800']]])
            ->assertSessionHasErrors('services');
    }

    public function test_the_customer_form_and_the_phone_get_the_options_and_minimum(): void
    {
        $this->as('manager');

        $this->get('/customers/create')->assertInertia(fn (Assert $page) => $page
            ->where('service_catalogue', fn ($catalogue) => collect($catalogue)->firstWhere('uuid', $this->tv->uuid)['min_price'] === '2000.00'
                && collect(collect($catalogue)->firstWhere('uuid', $this->tv->uuid)['price_options'])->pluck('amount')->all() === ['2000.00', '3000.00']));

        $this->getJson('/api/v1/services')->assertOk()->assertJsonFragment(['min_price' => '2000.00']);
    }

    private function register(string $name, string $price): TestResponse
    {
        return $this->post('/customers', [
            'zone_uuid' => $this->zone->uuid,
            'name' => $name,
            'phone' => '677'.random_int(100000, 999999),
            'status' => 'active',
            'services' => [['service_uuid' => $this->tv->uuid, 'price' => $price]],
        ]);
    }

    /** A front-desk role that may register and edit customers but not type prices. */
    private function asDeskClerk(): void
    {
        $role = Role::query()->create(['name' => 'desk', 'label' => 'Desk', 'is_system' => false]);
        $role->syncPermissions(['customers.view', 'customers.create', 'customers.update']);
        $this->as('desk');
    }

    private function as(string $role): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);
    }
}
