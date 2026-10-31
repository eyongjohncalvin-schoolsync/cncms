<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CommandRun;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The dashboard's setup checklist for a new company's super/admin
 * (App\Services\SetupGuideService, DashboardController::hideSetupGuide).
 * Inside swecom with kelvin's role switched; no month is published here, so
 * the guide always has an open step.
 */
class SetupGuideTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        CommandRun::query()->where('status', 'published')->update(['status' => 'rolled_back']);
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->kelvin->tokens()->delete();
    }

    private function actingAsRole(string $role): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role]);
        $this->kelvin->forceFill(['is_landlord' => false])->save();
        $this->actingAs($this->kelvin);
    }

    public function test_the_admin_sees_the_steps_ticked_from_the_company_data(): void
    {
        $this->actingAsRole('admin');

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('setup_guide.steps.0.key', 'zones')
            ->where('setup_guide.steps.0.done', Zone::query()->exists())
            ->where('setup_guide.steps.2.key', 'staff')
            ->where('setup_guide.steps.3.key', 'agent_app')
            ->where('setup_guide.steps.3.done', false)
            ->where('setup_guide.steps.4.key', 'first_month')
            ->where('setup_guide.steps.4.done', false)
            ->where('setup_guide.whatsapp', config('site.whatsapp')));
    }

    public function test_signing_in_on_the_mobile_app_ticks_the_agent_step(): void
    {
        $this->kelvin->createToken('mobile');
        $this->actingAsRole('super');

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('setup_guide.steps.3.done', true));
    }

    public function test_other_staff_never_see_it(): void
    {
        $this->actingAsRole('manager');

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('setup_guide', null));
        $this->post('/setup-guide/hide')->assertForbidden();
    }

    public function test_hiding_it_hides_it_for_the_whole_company_even_when_read_only(): void
    {
        $tenant = tenant();
        $tenant->demo_ends_on = now()->subMonth()->toDateString();
        $tenant->save();
        $this->actingAsRole('admin');

        $this->from('/dashboard')->post('/setup-guide/hide')->assertRedirect('/dashboard')->assertSessionMissing('error');

        $this->assertTrue((bool) Tenant::find('swecom')->setup_guide_hidden);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('setup_guide', null));
    }
}
