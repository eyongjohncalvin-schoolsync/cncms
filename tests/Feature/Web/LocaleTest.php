<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * English/French language-support infra (Phase 0 —
 * .ai/skills/cncms/cncms-context/references/language-support.md). Covers:
 *   - the language-switcher endpoint (PATCH /settings/locale);
 *   - App\Http\Middleware\ResolveLocale's resolution order (users.locale ->
 *     companies.default_locale -> config('app.locale'));
 *   - that a French-resolved locale actually reaches both consumers —
 *     the Inertia-shared `locale` prop (what react-i18next bootstraps
 *     from) and Laravel's own translator (lang/fr/validation.php).
 */
class LocaleTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    // -----------------------------------------------------------------
    // Language switcher endpoint
    // -----------------------------------------------------------------

    public function test_switching_locale_updates_the_users_locale_column_and_redirects_back(): void
    {
        $user = $this->actingAsRole('agent');

        $response = $this->from('/dashboard')->patch('/settings/locale', ['locale' => 'fr']);

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'locale' => 'fr'], 'pgsql');
    }

    public function test_any_authenticated_role_can_switch_their_own_locale(): void
    {
        // Unlike Settings/Company (admin-only), this is a personal
        // preference — every role, including the least-privileged, can
        // change their own.
        $user = $this->actingAsRole('worker');

        $response = $this->patch('/settings/locale', ['locale' => 'en']);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'locale' => 'en'], 'pgsql');
    }

    public function test_switching_to_an_unsupported_locale_is_rejected(): void
    {
        $user = $this->actingAsRole('admin');

        $response = $this->patch('/settings/locale', ['locale' => 'xx']);

        $response->assertSessionHasErrors('locale');
        $this->assertDatabaseMissing('users', ['id' => $user->id, 'locale' => 'xx'], 'pgsql');
    }

    public function test_guests_cannot_reach_the_locale_switcher(): void
    {
        $this->patch('/settings/locale', ['locale' => 'fr'])->assertRedirect('/login');
    }

    // -----------------------------------------------------------------
    // ResolveLocale resolution order
    // -----------------------------------------------------------------

    public function test_explicit_user_locale_wins_over_the_tenant_default(): void
    {
        Company::query()->update(['default_locale' => 'fr']);

        $user = $this->actingAsRole('manager');
        $user->forceFill(['locale' => 'en'])->save();

        $response = $this->get('/dashboard');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_tenant_default_locale_is_used_when_the_user_has_no_locale_set(): void
    {
        Company::query()->update(['default_locale' => 'fr']);

        $user = $this->actingAsRole('manager');
        $user->forceFill(['locale' => null])->save();

        $response = $this->get('/dashboard');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
    }

    public function test_falls_back_to_the_app_default_when_neither_user_nor_tenant_has_a_locale(): void
    {
        Company::query()->update(['default_locale' => null]);

        $user = $this->actingAsRole('manager');
        $user->forceFill(['locale' => null])->save();

        $response = $this->get('/dashboard');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locale', config('app.locale')));
    }

    // -----------------------------------------------------------------
    // The resolved locale actually reaches both real consumers
    // -----------------------------------------------------------------

    public function test_the_shared_inertia_locale_prop_is_french_for_a_french_resolved_request(): void
    {
        // This is what resources/tsx/app.tsx bootstraps react-i18next from
        // (see resources/tsx/lib/i18n.ts's syncLocale()) — the page itself
        // is React-rendered client-side (no SSR in this app), so the
        // resolved `locale` prop reaching the response correctly is the
        // server-testable half of "the nav visibly switches to French".
        $user = $this->actingAsRole('super');
        $user->forceFill(['locale' => 'fr'])->save();

        $response = $this->get('/dashboard');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('locale', 'fr'));
    }

    public function test_a_french_resolved_request_renders_genuine_french_validation_messages(): void
    {
        // Exercises the real lang/fr/validation.php file end-to-end: with
        // the caller's locale resolved to 'fr' *before* UpdateLocaleRequest
        // validates (App\Http\Middleware\ResolveLocale runs ahead of
        // SubstituteBindings/FormRequest resolution — see bootstrap/app.php),
        // a validation failure on this very endpoint should come back in
        // French, not Laravel's stock English default.
        $user = $this->actingAsRole('admin');
        $user->forceFill(['locale' => 'fr'])->save();

        $response = $this->patch('/settings/locale', ['locale' => 'not-a-real-locale']);

        // Exact-match against the genuine translation in lang/fr/validation.php
        // (the 'in' rule's message, :attribute substituted with the field
        // name) — not Laravel's stock English "The selected locale is invalid."
        $response->assertSessionHasErrors([
            'locale' => 'La valeur sélectionnée pour locale est invalide.',
        ]);
    }
}
