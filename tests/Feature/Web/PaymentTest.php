<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Web (session-auth, Inertia) counterpart of
 * tests/Feature/Api/PaymentTest.php + PaymentVerificationTest.php — same
 * PaymentService/PaymentVerificationService/PaymentPolicy underneath, just
 * exercised through App\Http\Controllers\PaymentController's Inertia
 * routes instead of the JSON API. See DashboardTest for the shared
 * setup/role-switching conventions this reuses.
 */
class PaymentTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function customer(): Customer
    {
        $zone = ZoneFactory::new()->create();

        return CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    public function test_index_renders_with_verification_status_tab_counts(): void
    {
        $customer = $this->customer();
        PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);
        PaymentFactory::new()->create(['customer_id' => $customer->id]);
        PaymentFactory::new()->rejected()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('super');

        $response = $this->get('/payments');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->has('payments.data')
                ->has('statusCounts.all')
                ->has('statusCounts.pending')
                ->has('statusCounts.verified')
                ->has('statusCounts.rejected'));
    }

    public function test_index_can_be_filtered_to_the_pending_tab(): void
    {
        $customer = $this->customer();
        PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);
        PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('super');

        $response = $this->get('/payments?verification_status=pending');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->where('filters.verification_status', 'pending')
                ->has('payments.data', 1));
    }

    public function test_an_agent_can_create_a_payment_which_ends_up_pending(): void
    {
        $customer = $this->customer();

        $this->actingAsRole('agent');

        $response = $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
        ]);

        $response->assertRedirect('/payments');
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'verification_status' => 'pending',
        ]);
    }

    public function test_a_managers_recorded_payment_is_auto_verified(): void
    {
        $customer = $this->customer();

        $this->actingAsRole('manager');

        $response = $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
        ]);

        $response->assertRedirect('/payments');
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'verification_status' => 'verified',
        ]);
    }

    public function test_a_manager_can_approve_a_pending_payment_via_the_web_verify_route(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $response = $this->post("/payments/{$payment->uuid}/verify", [
            'action' => 'approve',
            'momo_ref' => 'MOMO-20260823-001',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'verification_status' => 'verified',
        ]);
        $this->assertDatabaseHas('payment_verifications', [
            'payment_id' => $payment->id,
            'status' => 'approved',
            'momo_ref' => 'MOMO-20260823-001',
        ]);
    }

    public function test_rejecting_without_notes_fails_validation(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $response = $this->post("/payments/{$payment->uuid}/verify", [
            'action' => 'reject',
        ]);

        $response->assertSessionHasErrors('notes');
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'verification_status' => 'pending',
        ]);
    }

    public function test_an_agent_gets_a_403_attempting_to_verify_a_payment(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('agent');

        $response = $this->post("/payments/{$payment->uuid}/verify", [
            'action' => 'approve',
        ]);

        $response->assertStatus(403);
    }
}
