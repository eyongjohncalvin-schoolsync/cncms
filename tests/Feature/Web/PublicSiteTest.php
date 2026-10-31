<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\LandlordActivity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HomepageCompaniesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public website (App\Http\Controllers\SiteController): its pages in
 * English and French, and the "Cable companies running on CNCMS" strip
 * (App\Services\HomepageCompaniesService) with its landlord controls.
 *
 * The strip is exercised on the seeded swecom tenant, changed inside the
 * test transaction; the cache is cleared after each test.
 */
class PublicSiteTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        HomepageCompaniesService::forget();

        parent::tearDown();
    }

    private function swecom(): Tenant
    {
        return Tenant::query()->findOrFail('swecom');
    }

    public function test_the_home_page_sells_the_product_and_offers_the_demo_and_login(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Run your cable network', false)
            ->assertSee('Your office, in every agent', false)
            ->assertSee('+237 672 528 022')
            ->assertSee(route('register'), false)
            ->assertSee(route('login'), false)
            ->assertSee('https://wa.me/237672528022', false)
            ->assertSee('<html lang="en">', false);
    }

    public function test_the_language_switch_shows_the_site_in_french_and_back(): void
    {
        $this->get('/language/fr')->assertRedirect();

        $this->get('/')
            ->assertSee('<html lang="fr">', false)
            ->assertSee('Gérez votre réseau câblé', false)
            ->assertSee('Démo gratuite de 4 mois');

        $this->get('/language/en');
        $this->get('/')->assertSee('Run your cable network', false);
    }

    public function test_a_french_browser_gets_french_on_its_first_visit(): void
    {
        $this->withHeader('Accept-Language', 'fr-CM,fr;q=0.9,en;q=0.5')
            ->get('/')
            ->assertSee('<html lang="fr">', false);
    }

    public function test_signed_in_staff_go_straight_to_their_dashboard(): void
    {
        $this->actingAs(User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail());

        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get('/terms')->assertOk();
    }

    public function test_about_contact_privacy_and_terms_open_in_both_languages(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $this->get("/language/{$locale}");

            $this->get('/about')->assertOk()->assertSee('OneTech');
            $this->get('/contact')->assertOk()->assertSee('onetechcncms@gmail.com');
            $this->get('/privacy')->assertOk()->assertSee('onetechcncms@gmail.com');
            $this->get('/terms')->assertOk()->assertSee($locale === 'fr' ? '4 mois' : '4 months');
        }
    }

    public function test_an_approved_listed_company_appears_and_disappears_when_unlisted(): void
    {
        $tenant = $this->swecom();
        $tenant->forceFill(['show_on_homepage' => true, 'homepage_caption' => 'Multimedia Digital Cable Network · Kumba', 'is_active' => true, 'registration_status' => 'approved'])->save();

        $this->get('/')->assertSee($tenant->name)->assertSee('Multimedia Digital Cable Network · Kumba');

        $tenant->forceFill(['show_on_homepage' => false])->save();

        $this->get('/')->assertDontSee('Multimedia Digital Cable Network · Kumba');
    }

    public function test_a_listed_company_is_hidden_while_pending_or_deactivated(): void
    {
        $tenant = $this->swecom();
        $tenant->forceFill(['show_on_homepage' => true, 'homepage_caption' => 'Shown only when approved', 'registration_status' => 'pending'])->save();
        $this->get('/')->assertDontSee('Shown only when approved');

        $tenant->forceFill(['registration_status' => 'approved', 'is_active' => false])->save();
        $this->get('/')->assertDontSee('Shown only when approved');

        $tenant->forceFill(['is_active' => true])->save();
        $this->get('/')->assertSee('Shown only when approved');
    }

    public function test_initials_stand_in_for_a_missing_logo(): void
    {
        $this->assertSame('MDCN', HomepageCompaniesService::initials('MDCN'));
        $this->assertSame('MVC', HomepageCompaniesService::initials('Meme Vision Cable Network'));
    }

    public function test_the_landlord_lists_a_company_with_its_logo(): void
    {
        Storage::fake('public');
        $landlord = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $landlord->forceFill(['is_landlord' => true])->save();
        $this->actingAs($landlord);
        $this->swecom()->forceFill(['is_active' => true, 'registration_status' => 'approved'])->save();

        $this->post('/landlord/tenants/swecom', [
            '_method' => 'patch',
            'show_on_homepage' => '1',
            'homepage_caption' => 'Multimedia Digital Cable Network · Kumba',
            'homepage_logo' => UploadedFile::fake()->image('mdcn.png', 400, 400),
        ])->assertSessionHas('success');

        $tenant = $this->swecom();
        $this->assertTrue($tenant->show_on_homepage);
        Storage::disk('public')->assertExists($tenant->homepage_logo);
        $this->assertSame('tenant.homepage', LandlordActivity::query()->latest('id')->value('action'));

        auth()->logout();
        $this->get('/')->assertSee(Storage::disk('public')->url($tenant->homepage_logo), false);

        $this->actingAs($landlord);
        $this->post('/landlord/tenants/swecom', ['_method' => 'patch', 'show_on_homepage' => '1', 'remove_homepage_logo' => '1'])->assertSessionHas('success');
        Storage::disk('public')->assertMissing($tenant->homepage_logo);
        $this->assertNull($this->swecom()->homepage_logo);
    }

    public function test_an_svg_logo_is_refused(): void
    {
        $landlord = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $landlord->forceFill(['is_landlord' => true])->save();
        $this->actingAs($landlord);

        $this->post('/landlord/tenants/swecom', [
            '_method' => 'patch',
            'show_on_homepage' => '1',
            'homepage_logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors('homepage_logo');
    }
}
