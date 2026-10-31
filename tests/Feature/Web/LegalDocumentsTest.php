<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\LandlordActivity;
use App\Models\LegalAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\LegalDocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Landlord → Legal documents (Landlord\LegalDocumentController,
 * App\Services\LegalDocumentService): drafts, publishing, the public
 * pages, and the company admin's acceptance of new Terms
 * (EnsureLegalAccepted, LegalAcceptanceController).
 *
 * kelvin is the landlord; for the acceptance gate the same seeded user acts
 * inside swecom with their role switched, as in SettingsCompanyTest.
 */
class LegalDocumentsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.enforce_legal_acceptance' => true]);

        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->kelvin->forceFill(['is_landlord' => true])->save();
        $this->actingAs($this->kelvin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function saveDraft(string $type, array $overrides = []): void
    {
        $this->put("/landlord/legal/{$type}/draft", array_merge([
            'body_en' => "## Changed\n\nNew English terms.",
            'body_fr' => "## Modifié\n\nNouvelles conditions.",
            'change_note' => 'Clearer demo rules.',
            'requires_acceptance' => true,
        ], $overrides))->assertSessionHas('success');
    }

    private function acceptCurrentTermsForSwecom(): void
    {
        LegalAcceptance::create([
            'legal_document_version_id' => app(LegalDocumentService::class)->current(LegalDocumentVersion::TERMS)->id,
            'user_id' => $this->kelvin->id,
            'tenant_id' => 'swecom',
            'accepted_at' => now(),
        ]);
    }

    /**
     * Signs in as kelvin inside swecom with the given role.
     */
    private function inSwecomAs(string $role): void
    {
        $this->initializeTenant();
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role]);
        $this->kelvin->forceFill(['is_landlord' => false])->save();
        $this->actingAs($this->kelvin);
    }

    public function test_version_one_is_live_and_shown_on_the_site(): void
    {
        $this->get('/landlord/legal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landlord/Legal/Index')
                ->where('documents.0.type', 'terms')
                ->where('documents.0.version', 1)
                ->where('documents.2.type', 'contract')
                ->where('documents.2.version', null));

        $this->get('/terms')->assertOk()->assertSee('The free demo');
    }

    public function test_a_draft_stays_private_until_published_then_replaces_the_live_version(): void
    {
        $this->saveDraft('terms');

        $this->get('/terms')->assertDontSee('New English terms.');
        $this->get('/landlord/legal/terms/preview/draft?locale=fr')->assertOk()->assertSee('Nouvelles conditions.');

        $this->post('/landlord/legal/terms/publish')->assertSessionHas('success');

        $this->assertSame(
            ['1' => 'retired', '2' => 'published'],
            LegalDocumentVersion::query()->where('type', 'terms')->orderBy('version')->pluck('status', 'version')->mapWithKeys(fn ($status, $version) => [(string) $version => $status])->all(),
        );
        $this->get('/terms')->assertSee('New English terms.')->assertSee('Version 2');
        $this->get('/landlord/legal/terms/preview/1')->assertOk()->assertSee('The free demo');
        $this->assertSame('legal.published', LandlordActivity::query()->latest('id')->value('action'));
    }

    public function test_saving_twice_updates_the_same_draft(): void
    {
        $this->saveDraft('privacy');
        $this->saveDraft('privacy', ['body_en' => 'Second try.']);

        $drafts = LegalDocumentVersion::query()->where('type', 'privacy')->where('status', 'draft')->get();
        $this->assertCount(1, $drafts);
        $this->assertSame(2, $drafts->first()->version);
        $this->assertSame('Second try.', $drafts->first()->body_en);
        $this->assertFalse($drafts->first()->requires_acceptance, 'Only the Terms can ask companies to accept.');
    }

    public function test_a_discarded_draft_leaves_the_live_version_alone(): void
    {
        $this->saveDraft('terms');
        $this->delete('/landlord/legal/terms/draft')->assertSessionHas('success');

        $this->assertFalse(LegalDocumentVersion::query()->where('status', 'draft')->exists());
        $this->post('/landlord/legal/terms/publish')->assertSessionHas('error', 'There is no draft to publish.');
        $this->get('/terms')->assertSee('The free demo');
    }

    public function test_raw_html_in_a_document_is_shown_as_text_never_run(): void
    {
        $this->saveDraft('privacy', ['body_en' => 'Hello <script>alert(1)</script>']);
        $this->post('/landlord/legal/privacy/publish');

        $this->get('/privacy')->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_an_empty_french_text_falls_back_to_english(): void
    {
        $this->saveDraft('privacy', ['body_en' => 'English only for now.', 'body_fr' => '']);
        $this->post('/landlord/legal/privacy/publish');

        $this->get('/language/fr');
        $this->get('/privacy')->assertSee('English only for now.')->assertSee('Politique de confidentialité');
    }

    public function test_legal_documents_are_forbidden_to_non_landlords(): void
    {
        $this->kelvin->forceFill(['is_landlord' => false])->save();

        $this->get('/landlord/legal')->assertForbidden();
        $this->put('/landlord/legal/terms/draft', ['body_en' => 'x'])->assertForbidden();
        $this->post('/landlord/legal/terms/publish')->assertForbidden();
    }

    public function test_a_company_admin_must_accept_new_terms_before_carrying_on(): void
    {
        $this->saveDraft('terms');
        $this->post('/landlord/legal/terms/publish');

        $this->inSwecomAs('admin');

        $this->get('/dashboard')->assertRedirect(route('legal.accept.show'));
        $this->get('/legal/accept')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Legal/Accept')
            ->where('version', 2)
            ->where('change_note', 'Clearer demo rules.')
            ->where('can_accept', true));

        $this->post('/legal/accept', [])->assertSessionHasErrors('accept');
        $this->post('/legal/accept', ['accept' => '1'])->assertRedirect(route('dashboard'));

        $acceptance = LegalAcceptance::query()->where('tenant_id', 'swecom')->latest('id')->firstOrFail();
        $this->assertSame(2, $acceptance->documentVersion->version);
        $this->assertSame($this->kelvin->id, $acceptance->user_id);
        $this->assertNotNull($acceptance->ip_address);

        $this->get('/dashboard')->assertOk();
    }

    public function test_other_staff_are_never_stopped(): void
    {
        $this->inSwecomAs('agent');

        $this->get('/legal/accept')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_accept', false));
        $this->post('/legal/accept', ['accept' => '1'])->assertForbidden();
        $this->assertNotSame(route('legal.accept.show'), $this->get('/dashboard')->headers->get('Location'));
    }

    public function test_terms_published_without_must_accept_ask_nothing(): void
    {
        $this->acceptCurrentTermsForSwecom();
        $this->saveDraft('terms', ['requires_acceptance' => false, 'change_note' => 'Spelling.']);
        $this->post('/landlord/legal/terms/publish');

        $this->inSwecomAs('admin');

        $this->get('/dashboard')->assertOk();
    }

    public function test_the_landlord_sees_who_accepted_and_who_has_not(): void
    {
        $this->acceptCurrentTermsForSwecom();

        $this->get('/landlord/legal/terms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landlord/Legal/Edit')
                ->where('current.version', 1)
                ->where('acceptances.0.user', $this->kelvin->name)
                ->where('history.0.acceptances', 1));
    }
}
