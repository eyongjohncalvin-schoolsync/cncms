<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Manuscript;
use App\Models\Message;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Session-cookie web CRUD (App\Http\Controllers\CustomerController),
 * distinct from the Sanctum bearer-token API coverage in
 * tests/Feature/Api/CustomerTest.php. Same InteractsWithTenantRoles
 * tenant/transaction setup, but authenticates via actingAs() (session auth)
 * instead of tokenForRole() (which issues a Sanctum token).
 */
class CustomerTest extends TestCase
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

    public function test_index_renders_with_customer_data(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        // The real dev tenant has 549 seeded customers, so the unfiltered
        // index page returns a full page of them rather than just this
        // test's fixture — scope by a distinctive name via the search
        // filter instead of asserting an exact unfiltered page size (same
        // fix as tests/Feature/Web/ZoneTest.php's equivalent test).
        CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => 'CTEST-A Distinctive Customer']);

        $response = $this->get('/customers?search=CTEST-A');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 1)
                ->has('zones')
                ->has('filters'));
    }

    public function test_index_filters_by_zone_and_status(): void
    {
        $this->actingAsRole('super');

        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();

        CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'status' => 'active']);
        CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'status' => 'disconnected']);
        CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'status' => 'active']);

        $response = $this->get("/customers?zone_uuid={$zoneA->uuid}&status=active");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 1)
                ->where('customers.data.0.zone_uuid', $zoneA->uuid)
                ->where('customers.data.0.status', 'active'));
    }

    public function test_create_page_renders(): void
    {
        $this->actingAsRole('manager');

        $response = $this->get('/customers/create');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Customers/Create')->has('zones'));
    }

    public function test_store_creates_a_customer_and_redirects_with_a_flash_message(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();

        $response = $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'JANE DOE',
            'phone' => '677123456',
            'bill' => 2500,
            'level' => 'normal',
            'status' => 'active',
        ]);

        $response->assertRedirect('/customers');
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', ['name' => 'JANE DOE', 'zone_id' => $zone->id]);
    }

    public function test_store_fails_validation_for_invalid_level(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();

        $response = $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'JOHN DOE',
            'bill' => 2500,
            'level' => 'not-a-real-level',
        ]);

        $response->assertSessionHasErrors(['level']);
        $this->assertDatabaseMissing('customers', ['name' => 'JOHN DOE']);
    }

    public function test_store_fails_validation_when_phone_is_missing(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();

        $response = $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'NO PHONE DOE',
            'bill' => 2500,
        ]);

        $response->assertSessionHasErrors(['phone']);
        $this->assertDatabaseMissing('customers', ['name' => 'NO PHONE DOE']);
    }

    public function test_show_renders_customer_detail(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $response = $this->get("/customers/{$customer->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Show')
                ->where('customer.uuid', $customer->uuid)
                ->has('customer.recent_payments'));
    }

    /**
     * Covers App\Http\Controllers\CustomerController::lastPayment() — the
     * lightweight JSON endpoint backing the Record Payment single-payment
     * form's info panel (resources/tsx/pages/Payments/Create.tsx).
     */
    public function test_last_payment_returns_the_most_recent_payment(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        // Explicit, well-separated created_at timestamps — not just
        // insertion order — so this actually exercises the endpoint's
        // `latest('created_at')` ordering rather than passing by accident.
        PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
            'created_at' => now()->subDays(2),
        ]);
        $newest = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 5000,
            'verification_status' => 'pending',
            'created_at' => now()->subHour(),
        ]);

        $response = $this->getJson("/customers/{$customer->uuid}/last-payment");

        $response->assertOk()->assertExactJson([
            'payment' => [
                'uuid' => $newest->uuid,
                'amount' => '5000.00',
                'credit' => '0.00',
                'frequency' => 'monthly',
                'verification_status' => 'pending',
                'created_at' => $newest->created_at->toISOString(),
            ],
        ]);
    }

    public function test_last_payment_returns_null_when_customer_has_no_payments(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $response = $this->getJson("/customers/{$customer->uuid}/last-payment");

        $response->assertOk()->assertExactJson(['payment' => null]);
    }

    /**
     * CustomerPolicy::view() is true for anyone with tenant access (matches
     * test_agent_can_still_view_customers above), so there's no
     * role-based-forbidden case for this endpoint — only the guest
     * (unauthenticated) case, covered by test_guests_are_redirected_to_login
     * for the page routes and here for this JSON route specifically since
     * it isn't Inertia and shouldn't redirect to /login for an XHR request.
     */
    public function test_last_payment_requires_authentication(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->getJson("/customers/{$customer->uuid}/last-payment")->assertUnauthorized();
    }

    public function test_agent_can_view_a_customers_last_payment(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->getJson("/customers/{$customer->uuid}/last-payment")->assertOk();
    }

    public function test_manager_can_edit_and_update_a_customer(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $this->get("/customers/{$customer->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Customers/Edit')->where('customer.uuid', $customer->uuid));

        $response = $this->patch("/customers/{$customer->uuid}", [
            'status' => 'disconnected',
        ]);

        $response->assertRedirect('/customers');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'disconnected']);
    }

    public function test_super_can_delete_a_customer(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $response = $this->delete("/customers/{$customer->uuid}");

        $response->assertRedirect('/customers');
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    /**
     * Fix 2 (2026-08 audit): `manuscripts.customer_id` and
     * `messages.customer_id` were changed from cascadeOnDelete() to
     * restrictOnDelete() (2026_08_26_030000_restrict_delete_on_manuscripts_
     * and_messages_customer_id.php), matching payments.customer_id's
     * existing protection — deleting a customer with real billing/message
     * history must fail with a friendly message
     * (App\Services\CustomerService::delete()), never a raw 500, and the
     * customer's history rows must still exist afterward.
     */
    public function test_deleting_a_customer_with_manuscript_history_shows_a_friendly_error(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        $manuscript = ManuscriptFactory::new()->create(['customer_id' => $customer->id]);

        $response = $this->delete("/customers/{$customer->uuid}");

        $response->assertRedirect('/customers');
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'cannot be deleted',
            session('error'),
        );
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('manuscripts', ['id' => $manuscript->id, 'customer_id' => $customer->id]);
    }

    /**
     * Same protection, but for message (SMS) history instead of manuscripts
     * — both relations were fixed together for consistency (audit finding:
     * "message history is also customer history worth protecting the same
     * way").
     */
    public function test_deleting_a_customer_with_message_history_shows_a_friendly_error(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        $message = Message::query()->create([
            'customer_id' => $customer->id,
            'content' => 'Your bill is due.',
            'status' => 'sent',
            'type' => 'bill_reminder',
        ]);

        $response = $this->delete("/customers/{$customer->uuid}");

        $response->assertRedirect('/customers');
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'customer_id' => $customer->id]);
    }

    /**
     * Confirms the fix doesn't over-block: a genuinely empty customer record
     * (no payments, no manuscripts, no messages) — e.g. a freshly-imported
     * or test-only row — must still delete normally, exactly like
     * test_super_can_delete_a_customer above already proves for the
     * zero-history case. This test exists specifically alongside the two
     * friendly-error tests above so the contrast (empty deletes fine,
     * history-bearing is rejected) is obvious from reading this file alone.
     */
    public function test_a_customer_with_zero_history_can_still_be_deleted(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->assertSame(0, Manuscript::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, Payment::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, Message::query()->where('customer_id', $customer->id)->count());

        $response = $this->delete("/customers/{$customer->uuid}");

        $response->assertRedirect('/customers');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_agent_cannot_create_a_customer(): void
    {
        $this->actingAsRole('agent');

        $this->get('/customers/create')->assertForbidden();

        $zone = ZoneFactory::new()->create();

        $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'SHOULD NOT EXIST',
            'bill' => 2500,
        ])->assertForbidden();

        $this->assertDatabaseMissing('customers', ['name' => 'SHOULD NOT EXIST']);
    }

    public function test_agent_cannot_edit_a_customer(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->get("/customers/{$customer->uuid}/edit")->assertForbidden();
    }

    public function test_agent_can_still_view_customers(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->get('/customers')->assertOk();
        $this->get("/customers/{$customer->uuid}")->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/customers')->assertRedirect('/login');
    }

    public function test_manager_can_disconnect_a_customer(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $response = $this->patch("/customers/{$customer->uuid}/disconnect", [
            'note' => 'Owes 3 months.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'disconnected',
            'status_reason' => 'non_payment',
            'status_note' => 'Owes 3 months.',
        ]);
    }

    public function test_manager_can_suspend_a_customer_with_a_reason(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $response = $this->patch("/customers/{$customer->uuid}/suspend", [
            'reason' => 'tv_problem',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'suspended',
            'status_reason' => 'tv_problem',
        ]);
    }

    public function test_suspending_without_a_reason_fails_validation(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $response = $this->patch("/customers/{$customer->uuid}/suspend", []);

        $response->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
    }

    public function test_suspending_with_other_reason_requires_a_note(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $response = $this->patch("/customers/{$customer->uuid}/suspend", [
            'reason' => 'other',
        ]);

        $response->assertSessionHasErrors('note');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);

        $response = $this->patch("/customers/{$customer->uuid}/suspend", [
            'reason' => 'other',
            'note' => 'Moving out of the country for 6 months.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'suspended',
            'status_reason' => 'other',
            'status_note' => 'Moving out of the country for 6 months.',
        ]);
    }

    /**
     * 2026-08 owner decision (business-rules.md section 6): the reconnection
     * fine is opt-in admin discretion, unchecked/false by default — NOT
     * automatic and NOT required to confirm — for either `suspended` or
     * `disconnected`. This replaced the earlier behavior where the fine was
     * mandatory-and-automatic whenever reconnecting from `disconnected`
     * specifically (a `fine_collected` confirmation checkbox that had to be
     * `true` or the request failed validation).
     */
    public function test_manager_can_reconnect_a_suspended_customer_without_a_fine(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended']);

        $response = $this->patch("/customers/{$customer->uuid}/reconnect", []);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'active',
            'status_reason' => 'reconnected',
        ]);
        // No reconnection fine payment when include_fine wasn't sent.
        $this->assertDatabaseMissing('payments', ['customer_id' => $customer->id]);
    }

    /**
     * The core of the 2026-08 owner decision: reconnecting a `disconnected`
     * customer with NO `include_fine` in the request must succeed with no
     * validation error and no fine charged — the fine used to be mandatory
     * here (an `accepted` rule on `fine_collected`), that requirement is
     * gone.
     */
    public function test_reconnecting_a_disconnected_customer_without_including_the_fine_charges_no_fine(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);

        $response = $this->patch("/customers/{$customer->uuid}/reconnect", []);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'active',
            'status_reason' => 'reconnected',
        ]);
        $this->assertDatabaseMissing('payments', ['customer_id' => $customer->id]);
    }

    public function test_manager_can_reconnect_a_disconnected_customer_with_the_fine_included(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);

        $response = $this->patch("/customers/{$customer->uuid}/reconnect", [
            'include_fine' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'active',
            'status_reason' => 'reconnected',
        ]);
        // The 2,000 FCFA reconnection fine (business-rules.md section 6),
        // explicitly opted into via include_fine, recorded as a separate,
        // auto-verified payment (manager role).
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'amount' => 2000,
            'verification_status' => 'verified',
        ]);
    }

    /**
     * The other half of the 2026-08 owner decision: `include_fine` now works
     * identically for `suspended` as it does for `disconnected` — there is
     * no status-based distinction on the fine anymore, only on the
     * freeze/prepaid-carry-forward mechanics (ManuscriptCalculator), which
     * this test isn't about.
     */
    public function test_manager_can_reconnect_a_suspended_customer_with_the_fine_included(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended']);

        $response = $this->patch("/customers/{$customer->uuid}/reconnect", [
            'include_fine' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'active',
            'status_reason' => 'reconnected',
        ]);
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'amount' => 2000,
            'verification_status' => 'verified',
        ]);
    }

    /**
     * The new optional `arrears_payment` field (single-customer reconnect
     * only): recording a partial arrears payment as part of the same
     * reconnect action must land as a SECOND, separate Payment row from the
     * 2,000 FCFA reconnection fine — both real, both auto-verified (manager
     * role), so both are picked up by the next manuscript:calculate run
     * exactly like any other payment (see the end-to-end proof in
     * tests/Feature/CustomerReconnectArrearsPaymentTest.php).
     */
    public function test_manager_can_reconnect_a_disconnected_customer_with_a_partial_arrears_payment(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);

        $response = $this->patch("/customers/{$customer->uuid}/reconnect", [
            'include_fine' => true,
            'arrears_payment' => '1500.00',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'active',
            'status_reason' => 'reconnected',
        ]);
        // The fine payment...
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'amount' => 2000,
            'verification_status' => 'verified',
        ]);
        // ...and the arrears payment, as a genuinely separate row.
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'amount' => 1500,
            'verification_status' => 'verified',
        ]);
        $this->assertSame(2, Payment::query()->where('customer_id', $customer->id)->count());
    }

    /**
     * A suspended customer isn't charged a reconnection fine unless
     * `include_fine` is explicitly sent, but can still have an arrears
     * payment recorded alongside the reconnect — the arrears field is
     * entirely independent of `include_fine`/status.
     */
    public function test_manager_can_reconnect_a_suspended_customer_with_an_arrears_payment_and_no_fine(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended']);

        $response = $this->patch("/customers/{$customer->uuid}/reconnect", [
            'arrears_payment' => '800.00',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'amount' => 800,
            'verification_status' => 'verified',
        ]);
        $this->assertSame(1, Payment::query()->where('customer_id', $customer->id)->count());
    }

    /**
     * Regression: leaving arrears_payment blank (or sending 0) must behave
     * exactly as it did before this feature — reconnect succeeds, and only
     * the fine payment (if any) is recorded, never a spurious second row.
     */
    public function test_reconnecting_with_no_arrears_payment_records_no_extra_payment(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $disconnected = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);
        $suspended = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended']);

        $this->patch("/customers/{$disconnected->uuid}/reconnect", ['include_fine' => true])->assertRedirect();
        $this->assertSame(1, Payment::query()->where('customer_id', $disconnected->id)->count());

        $this->patch("/customers/{$suspended->uuid}/reconnect", ['arrears_payment' => '0'])->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $suspended->id, 'status' => 'active']);
        $this->assertSame(0, Payment::query()->where('customer_id', $suspended->id)->count());
    }

    public function test_arrears_payment_cannot_be_negative(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'suspended']);

        $response = $this->patch("/customers/{$customer->uuid}/reconnect", [
            'arrears_payment' => '-100',
        ]);

        $response->assertSessionHasErrors('arrears_payment');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'suspended']);
    }

    public function test_agent_gets_a_403_attempting_to_disconnect_a_customer(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $this->patch("/customers/{$customer->uuid}/disconnect", [])->assertForbidden();
    }

    public function test_worker_gets_a_403_attempting_to_suspend_a_customer(): void
    {
        $this->actingAsRole('worker');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $this->patch("/customers/{$customer->uuid}/suspend", ['reason' => 'tv_problem'])->assertForbidden();
    }

    public function test_agent_gets_a_403_attempting_to_reconnect_a_customer(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);

        $this->patch("/customers/{$customer->uuid}/reconnect", ['include_fine' => true])->assertForbidden();
    }
}
