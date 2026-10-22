<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The tenant logo after sign-in (Company::logoUrl(), CompanyLogoController):
 * streamed through the app because the media library's own /storage URL
 * can't reach tenant-suffixed storage, shared to every web page, returned by
 * the API's /auth/me, and served to the mobile app with its token.
 */
class CompanyLogoTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake('public');

        $this->company = Company::query()->firstOrFail();
        $this->company->clearMediaCollection('logo');
        Company::forgetCache();
        Company::flushMemo();
    }

    private function actingAsWorker(): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'worker', 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function uploadLogo(): int
    {
        $media = $this->company->addMedia(UploadedFile::fake()->image('logo.png', 120, 120))->toMediaCollection('logo');
        Company::forgetCache();
        Company::flushMemo();

        return $media->id;
    }

    public function test_every_role_sees_the_tenant_logo_in_the_app_chrome(): void
    {
        $mediaId = $this->uploadLogo();
        $this->actingAsWorker();

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('company.logo_url', url("/company/logo?v={$mediaId}")));

        $this->get("/company/logo?v={$mediaId}")
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_without_a_logo_the_url_is_null_and_the_route_404s(): void
    {
        $this->actingAsWorker();

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('company.logo_url', null));
        $this->get('/company/logo')->assertNotFound();
    }

    public function test_the_settings_preview_uses_the_working_url(): void
    {
        $mediaId = $this->uploadLogo();
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'super', 'branch_id' => null]);

        $this->actingAs($user)
            ->get('/settings/company')
            ->assertInertia(fn (Assert $page) => $page->where('company.logo_url', url("/company/logo?v={$mediaId}")));
    }

    public function test_the_mobile_app_gets_the_logo_url_from_me_and_the_image_with_its_token(): void
    {
        $mediaId = $this->uploadLogo();
        $token = $this->tokenForRole('agent');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('company.logo_url', url("/api/v1/company/logo?v={$mediaId}"));

        $this->withToken($token)->get('/api/v1/company/logo')->assertOk()->assertHeader('content-type', 'image/png');
    }

    public function test_the_logo_is_not_public(): void
    {
        $this->uploadLogo();

        $this->get('/company/logo')->assertRedirect('/login');
        $this->getJson('/api/v1/company/logo')->assertUnauthorized();
    }
}
