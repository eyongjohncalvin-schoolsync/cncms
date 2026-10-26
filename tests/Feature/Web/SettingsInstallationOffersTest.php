<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\CustomerCharge;
use App\Models\InstallationOffer;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\InstallationOfferService;
use Database\Factories\CustomerChargeFactory;
use Database\Factories\InstallationOfferFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Settings → Installation offers (docs/plans/installation-and-promotions.md,
 * phase 2, item 1): fee + free months per service, capped by the company
 * setting (D8), one default, admin/owner only.
 */
class SettingsInstallationOffersTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Company::query()->update(['max_free_months' => 3]);
        Company::forgetCache();
    }

    private function actAs(string $role): User
    {
        $user = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function tv(): Service
    {
        return Service::query()->where('slug', 'tv')->firstOrFail();
    }

    public function test_an_admin_adds_an_offer_with_free_months_per_service(): void
    {
        $this->actAs('admin');

        $this->post('/settings/installation-offers', [
            'name' => 'Launch offer',
            'fee' => '10000',
            'active' => true,
            'months' => [['service_uuid' => $this->tv()->uuid, 'months' => 2]],
        ])->assertSessionHasNoErrors();

        $offer = InstallationOffer::query()->where('name', 'Launch offer')->sole();
        $this->assertTrue($offer->active);
        $this->assertSame([$this->tv()->id => 2], $offer->monthsByService());

        $this->get('/settings/installation-offers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/InstallationOffers')
            ->where('max_free_months', 3));
    }

    public function test_free_months_on_an_offer_are_capped(): void
    {
        $this->actAs('admin');

        $this->post('/settings/installation-offers', [
            'name' => 'Too generous',
            'fee' => '10000',
            'months' => [['service_uuid' => $this->tv()->uuid, 'months' => 4]],
        ])->assertSessionHasErrors('months.0.months');
    }

    public function test_a_promotion_is_never_preselected(): void
    {
        // Owner, 2026-10-26: free months are the default; a running
        // promotion is picked on purpose. Older phone builds preselect an
        // offer marked default, so none is ever sent as one.
        $this->actAs('admin');
        $legacy = InstallationOfferFactory::new()->create(['is_default' => true]);

        $this->post('/settings/installation-offers', ['name' => 'Promo', 'fee' => '5000', 'is_default' => true])->assertSessionHasNoErrors();

        $this->assertFalse(InstallationOffer::query()->where('name', 'Promo')->sole()->is_default);
        $offers = app(InstallationOfferService::class)->availableForForm();
        $this->assertNotEmpty($offers);
        $this->assertFalse(collect($offers)->contains('is_default', true), "Not even {$legacy->name}, saved as default before.");
    }

    public function test_a_manager_cannot_set_offers(): void
    {
        $this->actAs('manager');

        $this->get('/settings/installation-offers')->assertForbidden();
        $this->post('/settings/installation-offers', ['name' => 'X', 'fee' => '1'])->assertForbidden();
    }

    public function test_an_offer_used_by_a_customer_can_only_be_deactivated(): void
    {
        $this->actAs('admin');
        $offer = InstallationOfferFactory::new()->create();
        CustomerChargeFactory::new()->create(['installation_offer_id' => $offer->id, 'status' => CustomerCharge::STATUS_PAID]);

        $this->delete("/settings/installation-offers/{$offer->uuid}")->assertSessionHasErrors('offer');
        $this->assertNotNull($offer->fresh());
    }

    public function test_the_cap_is_set_here(): void
    {
        $this->actAs('admin');

        $this->patch('/settings/installation-offers/cap', ['max_free_months' => 2])->assertSessionHasNoErrors();

        $this->assertSame(2, (int) Company::query()->value('max_free_months'));
    }
}
