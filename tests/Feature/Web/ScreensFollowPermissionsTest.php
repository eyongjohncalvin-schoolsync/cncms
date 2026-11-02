<?php

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Wave 4 of the permission audit (owner, 2026-11-02): a page sends only the
 * figures and records the role may see — the screen hides the rest.
 */
class ScreensFollowPermissionsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingWithRole(string $name, array $permissions): void
    {
        Role::query()->create(['name' => $name, 'label' => ucfirst($name), 'is_system' => false])->syncPermissions($permissions);
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $name, 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);
    }

    public function test_the_dashboard_sends_no_money_to_a_role_without_dashboard_money(): void
    {
        $this->actingWithRole('technician', ['complaints.view']);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('stats.monthly_income', '0')
            ->where('stats.total_arrears', '0')
            ->where('stats.total_customers', 0)
            ->where('stats.pending_verifications', 0));
    }

    public function test_the_customer_page_leaves_out_payments_and_arrears_adjustments_the_role_may_not_see(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'status' => 'active']);
        PaymentFactory::new()->create(['customer_id' => $customer->id]);
        $this->actingWithRole('viewer', ['customers.view']);

        $this->get("/customers/{$customer->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('customer.recent_payments', [])
            ->where('customer.arrears_adjustments', [])
            ->where('bills', []));
    }

    public function test_the_customer_page_includes_payments_for_a_role_that_may_see_them(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'status' => 'active']);
        PaymentFactory::new()->create(['customer_id' => $customer->id]);
        $this->actingWithRole('payments_viewer', ['customers.view', 'payments.view']);

        $this->get("/customers/{$customer->uuid}")->assertInertia(fn (Assert $page) => $page->has('customer.recent_payments', 1));
    }
}
