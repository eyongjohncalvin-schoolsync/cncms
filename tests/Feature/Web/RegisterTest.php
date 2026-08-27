<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Self-service registration & workspace creation (App\Http\Controllers\
 * RegisterController, App\Http\Controllers\GoogleAuthController) — see
 * .ai/skills/cncms/cncms-context/references/self-service-onboarding.md.
 *
 * Deliberately does NOT use DatabaseTransactions, for the same reason as
 * tests/Feature/Web/LandlordTest.php: provisioning a Tenant runs `CREATE
 * SCHEMA` on one connection and then migrates it on a separate
 * connection/session, which an outer test transaction on the central
 * connection would hide from that second session. Tests that provision a
 * real tenant clean it up themselves via $tenant->delete() (drops the
 * schema) and by deleting the User row they created.
 */
class RegisterTest extends TestCase
{
    private function registrationPayload(array $overrides = []): array
    {
        $slug = 'zreg'.now()->format('YmdHis').random_int(100, 999);

        return array_merge([
            'name' => 'New Operator',
            'email' => $slug.'@example.com',
            'password' => 'password123',
            'company_name' => 'Acme Cable Co',
            'company_location' => 'Downtown',
            'company_phone' => '677000000',
            'momo_number' => '677000000',
            'momo_name' => 'Acme Owner',
            'workspace_slug' => $slug,
        ], $overrides);
    }

    public function test_register_page_renders(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
    }

    public function test_submitting_registration_creates_a_pending_workspace_and_redirects_to_the_pending_page(): void
    {
        $payload = $this->registrationPayload();

        try {
            $response = $this->post('/register', $payload);

            $response->assertRedirect(route('workspace.pending'));

            $user = User::query()->where('email', $payload['email'])->first();
            $this->assertNotNull($user, 'Registering should have created a central User.');
            $this->assertSame('New Operator', $user->name);
            $this->assertNull($user->email_verified_at, 'A classic signup is not Google-verified.');
            $this->assertTrue(Hash::check('password123', $user->password));

            $tenant = Tenant::find($payload['workspace_slug']);
            $this->assertNotNull($tenant, 'Registering should have provisioned a Tenant.');
            $this->assertSame('pending', $tenant->registration_status);
            $this->assertFalse($tenant->isApproved());

            tenancy()->initialize($tenant);
            $tenantUser = TenantUser::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($tenantUser, 'A TenantUser should link the registrant to their new tenant.');
            $this->assertSame('super', $tenantUser->role);

            $company = Company::query()->first();
            $this->assertSame('Acme Cable Co', $company->name);
            $this->assertSame('Downtown', $company->location);
            $this->assertSame('677000000', $company->phone);
            $this->assertSame('677000000', $company->momo_number);
            $this->assertSame('Acme Owner', $company->momo_name);
            tenancy()->end();

            // The gate must actually block: session is authenticated and
            // tenant membership exists, but the workspace is still
            // pending, so hitting the dashboard must NOT succeed.
            $this->assertAuthenticatedAs($user);
            $this->get('/dashboard')->assertRedirect(route('workspace.pending'));
        } finally {
            Tenant::find($payload['workspace_slug'])?->delete();
            User::query()->where('email', $payload['email'])->delete();
        }
    }

    public function test_a_workspace_slug_collision_returns_a_validation_error(): void
    {
        $payload = $this->registrationPayload(['workspace_slug' => 'swecom']);

        $response = $this->post('/register', $payload);

        $response->assertSessionHasErrors(['workspace_slug']);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => $payload['email']], 'pgsql');
    }

    public function test_registration_requires_the_core_fields(): void
    {
        $response = $this->post('/register', []);

        $response->assertSessionHasErrors([
            'name', 'email', 'password',
            'company_name', 'company_location', 'company_phone',
            'workspace_slug',
        ]);
    }

    public function test_workspace_only_form_requires_authentication(): void
    {
        $this->get('/register/workspace')->assertRedirect('/login');
    }

    public function test_google_sign_in_for_an_existing_user_with_no_tenant_redirects_to_the_workspace_form(): void
    {
        $email = 'google-existing-'.random_int(1000, 9999).'@example.com';
        $user = User::factory()->create(['email' => $email]);

        Socialite::fake('google', SocialiteUser::fake([
            'email' => $email,
            'name' => $user->name,
        ]));

        try {
            $response = $this->get('/auth/google/callback');

            $response->assertRedirect(route('register.workspace'));
            $this->assertAuthenticatedAs($user);
        } finally {
            $user->delete();
        }
    }

    public function test_google_sign_in_for_a_brand_new_email_creates_a_user_and_redirects_to_the_workspace_form(): void
    {
        $email = 'brand-new-google-'.random_int(1000, 9999).'@example.com';

        Socialite::fake('google', SocialiteUser::fake([
            'email' => $email,
            'name' => 'Fresh Google User',
        ]));

        try {
            $response = $this->get('/auth/google/callback');

            $response->assertRedirect(route('register.workspace'));

            $user = User::query()->where('email', $email)->first();
            $this->assertNotNull($user);
            $this->assertNotNull($user->email_verified_at, 'Google already verified this email.');
            $this->assertAuthenticatedAs($user);
        } finally {
            User::query()->where('email', $email)->delete();
        }
    }

    public function test_google_sign_in_for_a_user_who_already_has_a_tenant_redirects_to_the_dashboard(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        Socialite::fake('google', SocialiteUser::fake([
            'email' => 'kelvin@shalomtech.dev',
            'name' => $user->name,
        ]));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
