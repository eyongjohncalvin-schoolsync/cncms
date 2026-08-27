<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The bulk customer-status workboard (App\Http\Controllers\
 * DisconnectionsController) — the primary "select several customers, act on
 * them together" workflow backed by App\Services\CustomerStatusService's
 * *Many() methods. Same InteractsWithTenantRoles tenant/transaction setup as
 * tests/Feature/Web/CustomerTest.php, whose single-customer disconnect/
 * suspend/reconnect route tests this complements rather than duplicates.
 */
class DisconnectionsTest extends TestCase
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

    public function test_manager_can_view_the_disconnections_board(): void
    {
        $this->actingAsRole('manager');

        $response = $this->get('/disconnections');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disconnections/Index')
                ->has('customers.data')
                ->has('zones')
                ->has('filters'));
    }

    public function test_agent_gets_a_403_viewing_the_disconnections_board(): void
    {
        $this->actingAsRole('agent');

        $this->get('/disconnections')->assertForbidden();
    }

    public function test_manager_can_bulk_disconnect_several_customers(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $response = $this->post('/disconnections/bulk-disconnect', [
            'customer_uuids' => [$customerA->uuid, $customerB->uuid],
            'note' => 'Zone sweep — non-payment.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', [
            'id' => $customerA->id,
            'status' => 'disconnected',
            'status_reason' => 'non_payment',
            'status_note' => 'Zone sweep — non-payment.',
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $customerB->id,
            'status' => 'disconnected',
        ]);
    }

    public function test_bulk_disconnect_skips_a_customer_that_is_already_disconnected(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $eligible = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);
        $alreadyDisconnected = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);

        $response = $this->post('/disconnections/bulk-disconnect', [
            'customer_uuids' => [$eligible->uuid, $alreadyDisconnected->uuid],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', ['id' => $eligible->id, 'status' => 'disconnected']);
        // The already-disconnected customer is skipped, not errored — the
        // rest of the batch still succeeds (mirrors bulk-verify's
        // partial-success behaviour).
        $this->assertDatabaseHas('customers', ['id' => $alreadyDisconnected->id, 'status' => 'disconnected']);
    }

    public function test_manager_can_bulk_suspend_customers_with_a_shared_reason(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $response = $this->post('/disconnections/bulk-suspend', [
            'customer_uuids' => [$customerA->uuid, $customerB->uuid],
            'reason' => 'zone_transfer',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', [
            'id' => $customerA->id,
            'status' => 'suspended',
            'status_reason' => 'zone_transfer',
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $customerB->id,
            'status' => 'suspended',
            'status_reason' => 'zone_transfer',
        ]);
    }

    public function test_bulk_suspend_without_a_reason_fails_validation(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $response = $this->post('/disconnections/bulk-suspend', [
            'customer_uuids' => [$customer->uuid],
        ]);

        $response->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
    }

    public function test_bulk_suspend_with_other_reason_requires_a_note(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $response = $this->post('/disconnections/bulk-suspend', [
            'customer_uuids' => [$customer->uuid],
            'reason' => 'other',
        ]);

        $response->assertSessionHasErrors('note');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
    }

    public function test_manager_can_bulk_reconnect_suspended_customers_without_a_fine(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended']);

        $response = $this->post('/disconnections/bulk-reconnect', [
            'customer_uuids' => [$customerA->uuid, $customerB->uuid],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', ['id' => $customerA->id, 'status' => 'active']);
        $this->assertDatabaseHas('customers', ['id' => $customerB->id, 'status' => 'active']);
        $this->assertDatabaseMissing('payments', ['customer_id' => $customerA->id]);
    }

    /**
     * 2026-08 owner decision (business-rules.md section 6): a bulk reconnect
     * with NO `include_fine` sent must succeed with no validation error and
     * charge no fine — even for a batch that includes `disconnected`
     * customers. This replaces the old mandatory-fine-when-any-disconnected
     * behavior (`fine_collected` was an `accepted`-rule requirement in that
     * case).
     */
    public function test_bulk_reconnect_without_including_the_fine_charges_no_fine_even_when_a_selected_customer_is_disconnected(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $suspended = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended']);
        $disconnected = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);

        $response = $this->post('/disconnections/bulk-reconnect', [
            'customer_uuids' => [$suspended->uuid, $disconnected->uuid],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('customers', ['id' => $suspended->id, 'status' => 'active']);
        $this->assertDatabaseHas('customers', ['id' => $disconnected->id, 'status' => 'active']);
        $this->assertDatabaseMissing('payments', ['customer_id' => $suspended->id]);
        $this->assertDatabaseMissing('payments', ['customer_id' => $disconnected->id]);
    }

    public function test_manager_can_bulk_reconnect_disconnected_customers_with_the_fine_included(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);

        $response = $this->post('/disconnections/bulk-reconnect', [
            'customer_uuids' => [$customerA->uuid, $customerB->uuid],
            'include_fine' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', ['id' => $customerA->id, 'status' => 'active']);
        $this->assertDatabaseHas('customers', ['id' => $customerB->id, 'status' => 'active']);
        $this->assertDatabaseHas('payments', ['customer_id' => $customerA->id, 'amount' => 2000, 'verification_status' => 'verified']);
        $this->assertDatabaseHas('payments', ['customer_id' => $customerB->id, 'amount' => 2000, 'verification_status' => 'verified']);
    }

    /**
     * The other half of the 2026-08 owner decision: include_fine works
     * identically for a batch of `suspended` customers as it does for
     * `disconnected` — no status-based distinction on the fine anymore.
     */
    public function test_manager_can_bulk_reconnect_suspended_customers_with_the_fine_included(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended']);

        $response = $this->post('/disconnections/bulk-reconnect', [
            'customer_uuids' => [$customer->uuid],
            'include_fine' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
        $this->assertDatabaseHas('payments', ['customer_id' => $customer->id, 'amount' => 2000, 'verification_status' => 'verified']);
    }

    /**
     * Bulk reconnect stays fine-only, exactly as before — the new
     * `arrears_payment` field added to the single-customer reconnect action
     * (App\Services\CustomerStatusService::reconnectOne()) was a deliberate
     * "only one-at-a-time" scoping decision. BulkReconnectCustomersRequest
     * doesn't declare the field at all, so even if a stray `arrears_payment`
     * key were sent on a bulk request, CustomerStatusService::reconnectMany()
     * never passes one through to reconnectOne() — only the fine payment (if
     * any) is ever recorded here.
     */
    public function test_bulk_reconnect_ignores_an_arrears_payment_field_and_stays_fine_only(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);

        $response = $this->post('/disconnections/bulk-reconnect', [
            'customer_uuids' => [$customer->uuid],
            'include_fine' => true,
            // Not a field BulkReconnectCustomersRequest declares — proves it
            // has no effect even if a client sends it anyway.
            'arrears_payment' => '5000.00',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
        $this->assertDatabaseHas('payments', ['customer_id' => $customer->id, 'amount' => 2000, 'verification_status' => 'verified']);
        $this->assertDatabaseMissing('payments', ['customer_id' => $customer->id, 'amount' => 5000]);
        $this->assertSame(1, Payment::query()->where('customer_id', $customer->id)->count());
    }

    public function test_agent_gets_a_403_bulk_disconnecting_customers(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $this->post('/disconnections/bulk-disconnect', [
            'customer_uuids' => [$customer->uuid],
        ])->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
    }

    public function test_worker_gets_a_403_bulk_suspending_customers(): void
    {
        $this->actingAsRole('worker');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $this->post('/disconnections/bulk-suspend', [
            'customer_uuids' => [$customer->uuid],
            'reason' => 'tv_problem',
        ])->assertForbidden();
    }
}
