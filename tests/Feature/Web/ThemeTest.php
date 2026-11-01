<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The website's Light / Dark / Match my device switch
 * (docs/plans/mobile-manager-app.md, wave 7): saved on the person, shared
 * with the page, and applied by app.blade.php before the first paint.
 */
class ThemeTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->user = User::query()->where('email', 'divine@shalomtech.dev')->firstOrFail();
        // Every role chooses their own — a worker included.
        TenantUser::query()->where('user_id', $this->user->id)->update(['role' => 'worker']);
        $this->user->update(['ui_theme' => null]);
        $this->actingAs($this->user);
    }

    public function test_the_choice_is_saved_on_the_person_and_shared_with_the_page(): void
    {
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('auth.user.ui_theme', 'system'));

        $this->from('/dashboard')->patch('/settings/theme', ['theme' => 'dark'])->assertRedirect('/dashboard');
        $this->assertSame('dark', $this->user->fresh()->ui_theme);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('auth.user.ui_theme', 'dark'));

        // Match my device is stored as no choice at all.
        $this->patch('/settings/theme', ['theme' => 'system'])->assertRedirect();
        $this->assertNull($this->user->fresh()->ui_theme);
    }

    public function test_only_the_three_choices_are_accepted(): void
    {
        $this->patch('/settings/theme', ['theme' => 'purple'])->assertSessionHasErrors('theme');
        $this->patch('/settings/theme', [])->assertSessionHasErrors('theme');
    }

    public function test_the_page_is_dark_before_it_paints(): void
    {
        $this->user->update(['ui_theme' => 'dark']);
        $this->get('/dashboard')->assertSee('<html lang="en" class="dark" data-theme="dark">', false);

        $this->user->update(['ui_theme' => 'light']);
        $this->get('/dashboard')->assertSee('data-theme="light"', false)->assertDontSee('class="dark"', false);

        // Match my device: the browser decides, in the inline script.
        $this->user->update(['ui_theme' => null]);
        $this->get('/dashboard')->assertSee('data-theme="system"', false)->assertSee('prefers-color-scheme: dark', false);
    }
}
