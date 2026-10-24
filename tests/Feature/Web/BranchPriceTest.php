<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\BranchPrice;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CustomerSubscriptionService;
use App\Services\InstallationOfferService;
use App\Support\BranchPrices;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\InstallationOfferFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Branches v2 P3b (docs/plans/branches-v2.md §4.6, D4): a branch may set its
 * own price for a service, option or installation offer — with a reason,
 * kept as history — otherwise headquarters' catalogue price applies; a
 * branch's repricing touches only its own subscribers, and headquarters'
 * never reprices a branch that sets its own.
 */
class BranchPriceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $headquarters;

    private Branch $buea;

    private Service $tv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        BranchPrices::flush();

        $this->headquarters = Branch::query()->where('is_headquarters', true)->sole();
        $this->buea = BranchFactory::new()->create(['name' => 'Buea']);
        $this->tv = Service::default();
        $this->tv->update(['price' => 2500]);
    }

    public function test_a_branch_price_applies_to_that_branch_only(): void
    {
        $this->setPrice('2000', 'Competition in Buea');

        $this->assertSame('2000.00', BranchPrices::for($this->buea->id)->service($this->tv->refresh()));
        $this->assertSame('2500.00', BranchPrices::for($this->headquarters->id)->service($this->tv));

        $change = BranchPrice::query()->latest('id')->firstOrFail();
        $this->assertSame('Competition in Buea', $change->reason);
        $this->assertSame($this->buea->id, $change->branch_id);
    }

    public function test_applying_reprices_only_the_branchs_current_subscribers(): void
    {
        $bueaCustomer = $this->subscriber($this->buea);
        $hqCustomer = $this->subscriber($this->headquarters);

        $this->setPrice('2000', 'Competition', apply: true)->assertSessionHas('success', 'Price saved. 1 subscriber repriced.');

        $this->assertSame('2000.00', (string) $bueaCustomer->refresh()->bill);
        $this->assertSame('2500.00', (string) $hqCustomer->refresh()->bill);
    }

    public function test_headquarters_repricing_skips_a_branch_that_sets_its_own_price(): void
    {
        $bueaCustomer = $this->subscriber($this->buea);
        $hqCustomer = $this->subscriber($this->headquarters);
        $this->setPrice('2000', 'Competition', apply: true);

        $this->tv->update(['price' => 3000]);
        app(CustomerSubscriptionService::class)->applyCataloguePriceToSubscribers($this->tv);

        $this->assertSame('3000.00', (string) $hqCustomer->refresh()->bill);
        $this->assertSame('2000.00', (string) $bueaCustomer->refresh()->bill);
    }

    public function test_an_empty_price_hands_back_to_headquarters(): void
    {
        $this->setPrice('2000', 'Competition');
        $this->setPrice(null, 'Competition over');

        $this->assertSame('2500.00', BranchPrices::for($this->buea->id)->service($this->tv->refresh()));
        $this->assertFalse(BranchPrices::for($this->buea->id)->overridesService($this->tv->id));
    }

    public function test_the_branchs_forms_offer_its_own_prices_and_fees(): void
    {
        $offer = InstallationOfferFactory::new()->create(['fee' => 10000]);
        $this->as('manager', $this->buea->id);
        $this->post("/branches/{$this->buea->uuid}/prices", ['target_type' => 'offer', 'target_uuid' => $offer->uuid, 'price' => '7500', 'reason' => 'Promo'])->assertSessionHasNoErrors();
        $this->setPrice('2000', 'Competition');

        $this->assertSame('7500.00', collect(app(InstallationOfferService::class)->availableForForm($this->buea->id))->firstWhere('uuid', $offer->uuid)['fee']);

        $customer = $this->subscriber($this->buea);
        $this->get("/customers/{$customer->uuid}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('service_catalogue', fn ($services) => collect($services)->firstWhere('uuid', $this->tv->uuid)['price'] === '2000.00'));
    }

    public function test_a_reason_is_required_and_only_the_branch_or_hq_may_set_it(): void
    {
        $this->as('manager', $this->buea->id);
        $this->post("/branches/{$this->buea->uuid}/prices", ['target_type' => 'service', 'target_uuid' => $this->tv->uuid, 'price' => '2000'])
            ->assertSessionHasErrors('reason');

        $limbe = BranchFactory::new()->create(['name' => 'Limbe']);
        $this->post("/branches/{$limbe->uuid}/prices", ['target_type' => 'service', 'target_uuid' => $this->tv->uuid, 'price' => '1', 'reason' => 'x'])
            ->assertForbidden();
        $this->post("/branches/{$this->headquarters->uuid}/prices", ['target_type' => 'service', 'target_uuid' => $this->tv->uuid, 'price' => '1', 'reason' => 'x'])
            ->assertForbidden();

        $this->assertSame(0, BranchPrice::query()->count());
    }

    public function test_the_settings_page_shows_the_price_list_and_history(): void
    {
        $this->setPrice('2000', 'Competition in Buea');

        $this->get("/branches/{$this->buea->uuid}/settings")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('prices.services', fn ($services) => collect($services)->firstWhere('uuid', $this->tv->uuid)['branch_price'] === '2000.00')
            ->where('price_history.0.reason', 'Competition in Buea'));
    }

    private function setPrice(?string $price, string $reason, bool $apply = false): TestResponse
    {
        $this->as('manager', $this->buea->id);

        return $this->post("/branches/{$this->buea->uuid}/prices", [
            'target_type' => 'service',
            'target_uuid' => $this->tv->uuid,
            'price' => $price,
            'reason' => $reason,
            'apply_to_subscribers' => $apply,
        ])->assertSessionHasNoErrors();
    }

    private function subscriber(Branch $branch): Customer
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $branch->id])->id, 'bill' => 2500]);
        CustomerSubscription::query()->create(['customer_id' => $customer->id, 'service_id' => $this->tv->id, 'price' => 2500]);

        return $customer;
    }

    private function as(string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }
}
