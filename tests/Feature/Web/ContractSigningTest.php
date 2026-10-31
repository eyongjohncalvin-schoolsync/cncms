<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\ContractSignature;
use App\Models\LegalAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ContractService;
use App\Services\LegalDocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Online signing of the OneTech service contract (ContractController,
 * App\Services\ContractService): the template's blanks filled in, the
 * typed-name signature with its PDF, re-signing a new version, and the
 * landlord's view. Inside swecom with kelvin's role switched, as in
 * DemoPeriodTest.
 */
class ContractSigningTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ContractService::DISK);
        $this->travelTo('2026-11-05 10:00:00');
        $this->initializeTenant();
        $this->tenant = tenant();
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
    }

    private function actingAsRole(string $role, bool $landlord = false): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role]);
        $this->kelvin->forceFill(['is_landlord' => $landlord])->save();
        $this->actingAs($this->kelvin);
    }

    /**
     * Publishes the version 1 draft the migration added, or a new version.
     */
    private function publishContract(?string $body = null): LegalDocumentVersion
    {
        $legal = app(LegalDocumentService::class);

        if ($body !== null) {
            $legal->saveDraft(LegalDocumentVersion::CONTRACT, ['body_en' => $body, 'change_note' => 'Longer notice.'], $this->kelvin);
        }

        return $legal->publish(LegalDocumentVersion::CONTRACT, $this->kelvin);
    }

    private function sign(): void
    {
        $this->post('/settings/contract/sign', ['signer_name' => 'Kelvin Ebai', 'signer_title' => 'Director', 'agree' => '1'])
            ->assertSessionHas('success');
    }

    public function test_version_one_arrives_as_a_draft_for_the_landlord_to_review(): void
    {
        $draft = app(LegalDocumentService::class)->draft(LegalDocumentVersion::CONTRACT);

        $this->assertNotNull($draft);
        $this->assertSame(1, $draft->version);
        $this->assertStringContainsString('{company_name}', $draft->body_en);
        $this->assertStringContainsString('{company_name}', (string) $draft->body_fr);
        $this->assertNull(app(ContractService::class)->current());
    }

    public function test_the_admin_reads_the_contract_with_their_company_details_filled_in(): void
    {
        $this->publishContract();
        $this->tenant->demo_starts_on = '2026-11-01';
        $this->tenant->demo_ends_on = '2027-02-28';
        $this->tenant->save();
        $this->actingAsRole('admin');

        $this->get('/settings/contract')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Contract')
            ->where('needs_signature', true)
            ->where('contractPending', 1)
            ->where('contract.body_html', fn (string $html) => str_contains($html, Company::cached()->name)
                && str_contains($html, '1 November 2026')
                && str_contains($html, '28 February 2027')
                && ! str_contains($html, '{company_name}')));
    }

    public function test_signing_keeps_the_signed_text_a_pdf_and_the_acceptance(): void
    {
        $contract = $this->publishContract();
        $this->actingAsRole('admin');

        $this->post('/settings/contract/sign', ['signer_name' => 'Kelvin Ebai'])->assertSessionHasErrors('agree');
        $this->post('/settings/contract/sign', ['signer_name' => '', 'agree' => '1'])->assertSessionHasErrors('signer_name');

        $this->sign();

        $signature = ContractSignature::query()->where('tenant_id', 'swecom')->sole();
        $this->assertSame($contract->id, $signature->legal_document_version_id);
        $this->assertSame('Kelvin Ebai', $signature->signer_name);
        $this->assertSame('Director', $signature->signer_title);
        $this->assertStringContainsString('represented by **Kelvin Ebai**', $signature->body);
        $this->assertStringContainsString('Signed online on 5 November 2026', $signature->body);
        $this->assertSame(hash('sha256', $signature->body), $signature->body_sha256);
        $this->assertNotNull($signature->ip_address);
        Storage::disk(ContractService::DISK)->assertExists($signature->pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk(ContractService::DISK)->get($signature->pdf_path));
        $this->assertTrue(LegalAcceptance::query()->where('tenant_id', 'swecom')->where('legal_document_version_id', $contract->id)->exists());

        $this->get('/settings/contract')->assertInertia(fn (Assert $page) => $page
            ->where('needs_signature', false)
            ->where('contractPending', null)
            ->where('signatures.0.signer_name', 'Kelvin Ebai'));
        $this->get("/settings/contract/{$signature->id}/pdf")->assertOk()->assertDownload();

        $this->post('/settings/contract/sign', ['signer_name' => 'Kelvin Ebai', 'agree' => '1'])->assertSessionHas('error');
        $this->assertSame(1, ContractSignature::query()->where('tenant_id', 'swecom')->count());
    }

    public function test_a_new_version_asks_for_a_new_signature_and_keeps_the_old_copy(): void
    {
        $this->publishContract();
        $this->actingAsRole('super');
        $this->sign();

        $this->publishContract("## New\n\n{company_name} gives 60 days' notice.");

        $this->get('/settings/contract')->assertInertia(fn (Assert $page) => $page
            ->where('needs_signature', true)
            ->where('contract.version', 2)
            ->where('contract.change_note', 'Longer notice.')
            ->has('signatures', 1));

        $this->sign();
        $this->assertSame([2, 1], ContractSignature::query()->where('tenant_id', 'swecom')->with('documentVersion')->latest('id')->get()->pluck('documentVersion.version')->all());
    }

    public function test_a_read_only_company_can_still_sign(): void
    {
        $this->publishContract();
        $this->tenant->demo_ends_on = '2026-10-31';
        $this->tenant->save();
        $this->actingAsRole('admin');

        $this->sign();
        $this->assertTrue(ContractSignature::query()->where('tenant_id', 'swecom')->exists());
    }

    public function test_other_staff_cannot_see_or_sign_the_contract(): void
    {
        $this->publishContract();
        $this->actingAsRole('manager');

        $this->get('/settings/contract')->assertForbidden();
        $this->post('/settings/contract/sign', ['signer_name' => 'Someone', 'agree' => '1'])->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('contractPending', null));
    }

    public function test_the_landlord_sees_who_signed_and_downloads_the_pdf(): void
    {
        $this->publishContract();
        $this->actingAsRole('admin');
        $this->sign();
        $signature = ContractSignature::query()->where('tenant_id', 'swecom')->sole();

        $this->actingAsRole('super', landlord: true);

        $this->get('/landlord/tenants/swecom/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('contract_version', 1)
            ->where('contracts.0.signer_name', 'Kelvin Ebai'));
        $this->get('/landlord/legal/contract')->assertInertia(fn (Assert $page) => $page
            ->where('acceptances.0.user', $this->kelvin->name)
            ->where('waiting', fn ($waiting) => ! collect($waiting)->contains(Tenant::find('swecom')->name)));
        $this->get("/landlord/contracts/{$signature->id}/pdf")->assertOk()->assertDownload();

        $this->kelvin->forceFill(['is_landlord' => false])->save();
        $this->get("/landlord/contracts/{$signature->id}/pdf")->assertForbidden();
    }
}
