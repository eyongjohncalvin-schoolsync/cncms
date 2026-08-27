<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
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

        // Explicitly 'active': CustomerFactory's default state picks status
        // randomly (including a 20% chance of 'disconnected'), which would
        // make every test relying on this helper intermittently fail now
        // that disconnected customers are blocked from payment (see
        // StorePaymentRequest). Tests that specifically exercise the block
        // create their own customer with an explicit status instead of
        // using this helper.
        return CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);
    }

    private function actingAsRole(string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Worker + the narrow per-user payment-recording grant (Case 1 —
     * PaymentPolicy::create()'s doc comment). $branchId optionally also
     * fences the worker to one branch, same as actingAsRole() above.
     */
    private function actingAsWorkerWithPaymentFlag(?int $branchId = null): User
    {
        $user = $this->actingAsRole('worker', $branchId);
        TenantUser::query()->where('user_id', $user->id)->update(['can_record_payments' => true]);

        return $user;
    }

    /**
     * Agent role scoped to a specific zone (Case 2 —
     * PaymentPolicy::verify()'s doc comment) — creates the real Agent row
     * TenantContext::resolve() reads zoneId from, for the same already-
     * committed seeded owner actingAsRole() reuses (see
     * InteractsWithTenantRoles's class doc comment for why a fresh
     * factory-created central user can't be used here).
     */
    private function actingAsAgentInZone(int $zoneId): User
    {
        $user = $this->actingAsRole('agent');

        AgentFactory::new()->create(['zone_id' => $zoneId, 'user_id' => $user->id]);

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

    // -----------------------------------------------------------------
    // Default month scoping — PaymentController::index() defaults to the
    // current calendar month whenever the caller supplies neither an
    // explicit from/to range nor ?scope=all, per that method's doc comment.
    //
    // Every request below is additionally scoped to `customer_uuid` for
    // this test's own freshly-created customer: the real `swecom` tenant
    // database these Web feature tests run against (see
    // InteractsWithTenantRoles::initializeTenant()) already carries its own
    // pre-existing payment history untouched by DatabaseTransactions'
    // per-test rollback, so an unscoped "does the list contain my uuid"
    // check would be paginated out by that unrelated data long before it's
    // a reliable assertion.
    // -----------------------------------------------------------------

    public function test_index_default_view_excludes_a_payment_from_a_past_month(): void
    {
        $customer = $this->customer();
        $thisMonth = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'created_at' => now(),
        ]);
        $lastMonth = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2),
        ]);

        $this->actingAsRole('super');

        $response = $this->get('/payments?'.http_build_query(['customer_uuid' => $customer->uuid]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->where('filters.scope', null)
                ->where(
                    'payments.data',
                    fn ($data) => collect($data)->pluck('uuid')->contains($thisMonth->uuid)
                        && ! collect($data)->pluck('uuid')->contains($lastMonth->uuid),
                ));
    }

    public function test_index_explicit_from_to_for_the_past_month_includes_that_payment(): void
    {
        $customer = $this->customer();
        $pastMonthDate = now()->subMonthNoOverflow()->startOfMonth()->addDays(2);
        $lastMonth = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'created_at' => $pastMonthDate,
        ]);

        $this->actingAsRole('super');

        $response = $this->get('/payments?'.http_build_query([
            'customer_uuid' => $customer->uuid,
            'from' => $pastMonthDate->copy()->startOfMonth()->toDateString(),
            'to' => $pastMonthDate->copy()->endOfMonth()->toDateString(),
        ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->where(
                    'payments.data',
                    fn ($data) => collect($data)->pluck('uuid')->contains($lastMonth->uuid),
                ));
    }

    public function test_index_scope_all_includes_a_payment_from_a_past_month(): void
    {
        $customer = $this->customer();
        $lastMonth = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2),
        ]);

        $this->actingAsRole('super');

        $response = $this->get('/payments?'.http_build_query(['customer_uuid' => $customer->uuid, 'scope' => 'all']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->where('filters.scope', 'all')
                ->where(
                    'payments.data',
                    fn ($data) => collect($data)->pluck('uuid')->contains($lastMonth->uuid),
                ));
    }

    public function test_index_default_view_still_includes_the_current_months_own_payments(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'created_at' => now(),
        ]);

        $this->actingAsRole('super');

        $response = $this->get('/payments?'.http_build_query(['customer_uuid' => $customer->uuid]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'payments.data',
                    fn ($data) => collect($data)->pluck('uuid')->contains($payment->uuid),
                ));
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

    // -----------------------------------------------------------------
    // Search filter — matches the payment's customer's name (partial,
    // case-insensitive) or phone (partial). A payment has no free-text
    // field of its own worth searching, so PaymentRepository::paginate()
    // reuses CustomerRepository::paginate()'s identical ILIKE/LIKE
    // 'search' idiom via a whereHas('customer', ...) clause.
    // -----------------------------------------------------------------

    public function test_index_search_by_customer_name_returns_only_that_customers_payments(): void
    {
        $zone = ZoneFactory::new()->create();
        $target = CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => 'Zztestsearch Alpha', 'status' => 'active']);
        $other = CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => 'Someone Else Entirely', 'status' => 'active']);

        $targetPayment = PaymentFactory::new()->create(['customer_id' => $target->id, 'created_at' => now()]);
        $otherPayment = PaymentFactory::new()->create(['customer_id' => $other->id, 'created_at' => now()]);

        $this->actingAsRole('super');

        $response = $this->get('/payments?'.http_build_query(['search' => 'zztestsearch']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->where('filters.search', 'zztestsearch')
                ->where(
                    'payments.data',
                    fn ($data) => collect($data)->pluck('uuid')->contains($targetPayment->uuid)
                        && ! collect($data)->pluck('uuid')->contains($otherPayment->uuid),
                ));
    }

    public function test_index_search_by_phone_partial_match_works(): void
    {
        $zone = ZoneFactory::new()->create();
        $target = CustomerFactory::new()->create(['zone_id' => $zone->id, 'phone' => '699887766', 'status' => 'active']);
        $other = CustomerFactory::new()->create(['zone_id' => $zone->id, 'phone' => '677001122', 'status' => 'active']);

        $targetPayment = PaymentFactory::new()->create(['customer_id' => $target->id, 'created_at' => now()]);
        $otherPayment = PaymentFactory::new()->create(['customer_id' => $other->id, 'created_at' => now()]);

        $this->actingAsRole('super');

        // Partial match on a substring in the middle of the phone number.
        $response = $this->get('/payments?'.http_build_query(['search' => '8877']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'payments.data',
                    fn ($data) => collect($data)->pluck('uuid')->contains($targetPayment->uuid)
                        && ! collect($data)->pluck('uuid')->contains($otherPayment->uuid),
                ));
    }

    public function test_index_search_with_no_matches_returns_an_empty_non_error_result(): void
    {
        $customer = $this->customer();
        PaymentFactory::new()->create(['customer_id' => $customer->id, 'created_at' => now()]);

        $this->actingAsRole('super');

        $response = $this->get('/payments?'.http_build_query(['search' => 'zzznonexistentcustomerxyz123']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->has('payments.data', 0));
    }

    /**
     * Proves search composes with an already-existing filter rather than
     * replacing it — both narrow the same query at once, same as
     * verification_status + frequency already do together.
     */
    public function test_index_search_composes_with_verification_status_filter(): void
    {
        $zone = ZoneFactory::new()->create();
        $target = CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => 'Zzcompose Search Target', 'status' => 'active']);

        $pending = PaymentFactory::new()->pending()->create(['customer_id' => $target->id, 'created_at' => now()]);
        $verified = PaymentFactory::new()->create(['customer_id' => $target->id, 'created_at' => now()]);

        $this->actingAsRole('super');

        $response = $this->get('/payments?'.http_build_query(['search' => 'zzcompose', 'verification_status' => 'pending']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'zzcompose')
                ->where('filters.verification_status', 'pending')
                ->where(
                    'payments.data',
                    fn ($data) => collect($data)->pluck('uuid')->contains($pending->uuid)
                        && ! collect($data)->pluck('uuid')->contains($verified->uuid),
                ));
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

    public function test_bulk_store_records_one_payment_per_customer_at_their_own_bill(): void
    {
        $zone = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 4000, 'status' => 'active']);

        $this->actingAsRole('manager');

        $response = $this->post('/payments/bulk', [
            'customer_uuids' => [$customerA->uuid, $customerB->uuid],
            'frequency' => 'monthly',
        ]);

        $response->assertRedirect('/payments');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('payments', [
            'customer_id' => $customerA->id,
            'amount' => 2500,
            'verification_status' => 'verified',
        ]);
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customerB->id,
            'amount' => 4000,
            'verification_status' => 'verified',
        ]);
    }

    /**
     * Audit scenario: PaymentService::createBulk() pays each customer at
     * exactly their own current `bill`, resolved via
     * CustomerRepositoryInterface::findByUuid() — a live, uncached DB read
     * (see App\Repositories\Eloquent\CustomerRepository::findByUuid()).
     * This proves that's genuinely live: if an admin bulk-updates rates
     * (App\Services\CustomerService::bulkUpdateBill()) and then someone
     * immediately runs a bulk payment for the same customers, the payment
     * amount reflects the NEW rate, not a stale pre-update figure — no
     * caching layer sits between the two operations.
     */
    public function test_bulk_payment_run_immediately_after_a_bulk_rate_update_charges_the_new_rate(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);

        $this->actingAsRole('admin');

        $this->post('/customers/bulk-update-bill', [
            'customer_uuids' => [$customer->uuid],
            'mode' => 'set',
            'value' => 3200,
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'bill' => 3200]);

        $response = $this->post('/payments/bulk', [
            'customer_uuids' => [$customer->uuid],
            'frequency' => 'monthly',
        ]);

        $response->assertRedirect('/payments');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'amount' => 3200,
            'verification_status' => 'verified',
        ]);
    }

    public function test_bulk_store_is_forbidden_for_a_worker(): void
    {
        $customer = $this->customer();

        $this->actingAsRole('worker');

        $response = $this->post('/payments/bulk', [
            'customer_uuids' => [$customer->uuid],
            'frequency' => 'monthly',
        ]);

        $response->assertStatus(403);
    }

    public function test_bulk_verify_approves_only_payments_that_exactly_match_the_customers_bill(): void
    {
        $customer = $this->customer();
        $matching = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id, 'amount' => 2500]);
        $mismatched = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id, 'amount' => 1000]);

        $this->actingAsRole('manager');

        $response = $this->post('/payments/bulk-verify', [
            'payment_uuids' => [$matching->uuid, $mismatched->uuid],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('payments', [
            'id' => $matching->id,
            'verification_status' => 'verified',
        ]);
        // The mismatched payment is skipped, not rejected — it stays
        // pending for a human to review individually.
        $this->assertDatabaseHas('payments', [
            'id' => $mismatched->id,
            'verification_status' => 'pending',
        ]);
    }

    /**
     * PaymentPolicy::bulkVerify() was widened to include `agent` alongside
     * verify() gaining a zone-scoped agent path (Case 2) — an agent may now
     * reach this endpoint at all (no longer a blanket 403), but that
     * class-level check has no target Payment to zone-fence against. An
     * agent with no Agent row at all — i.e. no resolvable zone
     * (TenantContext::zoneId is null) — must therefore still verify
     * nothing via bulk-verify: PaymentVerificationService::verifyMany()'s
     * per-item zone re-check skips (not verifies) every payment for them.
     */
    public function test_bulk_verify_verifies_nothing_for_an_agent_with_no_resolvable_zone(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id, 'amount' => 2500]);

        $this->actingAsRole('agent'); // no Agent row created — zoneId resolves to null

        $response = $this->post('/payments/bulk-verify', [
            'payment_uuids' => [$payment->uuid],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'verification_status' => 'pending',
        ]);
    }

    /**
     * The specific security-review-flagged bypass (Case 2's must-fix): an
     * agent zone-fenced to zoneA must NOT be able to use the bulk-verify
     * endpoint to approve a payment for a customer in zoneB, even though
     * bulkVerify() now permits agents to call the endpoint at all. This is
     * the per-item re-check inside PaymentVerificationService::verifyMany()
     * doing its job — a same-zone payment submitted in the same batch is
     * still verified normally, proving the fence is per-item, not "block
     * the whole batch".
     */
    public function test_agent_cannot_bypass_the_zone_fence_via_bulk_verify(): void
    {
        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'bill' => 2500, 'status' => 'active']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'bill' => 3000, 'status' => 'active']);
        $paymentA = PaymentFactory::new()->pending()->create(['customer_id' => $customerA->id, 'amount' => 2500]);
        $paymentB = PaymentFactory::new()->pending()->create(['customer_id' => $customerB->id, 'amount' => 3000]);

        $this->actingAsAgentInZone($zoneA->id);

        $response = $this->post('/payments/bulk-verify', [
            'payment_uuids' => [$paymentA->uuid, $paymentB->uuid],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Same-zone payment: verified normally.
        $this->assertDatabaseHas('payments', [
            'id' => $paymentA->id,
            'verification_status' => 'verified',
        ]);
        // Different-zone payment: skipped, NOT verified — the fence
        // an agent must not be able to bypass via this endpoint.
        $this->assertDatabaseHas('payments', [
            'id' => $paymentB->id,
            'verification_status' => 'pending',
        ]);
    }

    /**
     * Audit follow-up to the test above: a payment skipped by the zone
     * fence must be left in a genuinely normal 'pending' state — not some
     * side channel that quietly makes it unreachable — so a manager/admin
     * with full access can still verify it individually afterward.
     */
    public function test_a_payment_skipped_by_the_agent_zone_fence_is_still_verifiable_by_a_manager_afterward(): void
    {
        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'bill' => 3000, 'status' => 'active']);
        $paymentB = PaymentFactory::new()->pending()->create(['customer_id' => $customerB->id, 'amount' => 3000]);

        $this->actingAsAgentInZone($zoneA->id);
        $this->post('/payments/bulk-verify', ['payment_uuids' => [$paymentB->uuid]]);

        $this->assertDatabaseHas('payments', ['id' => $paymentB->id, 'verification_status' => 'pending']);

        $this->actingAsRole('manager');

        $response = $this->post("/payments/{$paymentB->uuid}/verify", ['action' => 'approve']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('payments', ['id' => $paymentB->id, 'verification_status' => 'verified']);
    }

    public function test_recording_a_single_payment_for_an_active_customer_still_works(): void
    {
        // Regression proof: the new disconnected/suspended block must not
        // affect the ordinary, already-working case.
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
            'amount' => 2500,
        ]);
    }

    /**
     * Audit scenario: unlike mobile sync (App\Services\SyncService::
     * pushPayment(), protected by the client-generated `local_uuid` unique
     * column), the web POST /payments route has no idempotency key at all —
     * StorePaymentRequest doesn't accept local_uuid, and PaymentData::
     * fromArray() only ever gets a local_uuid from SyncService's own call
     * site. A network retry or a double-click on the submit button before
     * the page navigates away sends two structurally identical requests,
     * and nothing on this path stops both from creating a Payment row. This
     * test documents the ACTUAL current behavior for the audit report — it
     * is not asserting the desired behavior. If a fix lands (e.g. a
     * client-generated idempotency key mirroring local_uuid), this
     * assertion should flip to expecting exactly 1 row.
     */
    public function test_two_identical_back_to_back_web_submissions_both_create_a_payment(): void
    {
        $customer = $this->customer();

        $this->actingAsRole('manager');

        $payload = [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
        ];

        $this->post('/payments', $payload)->assertRedirect('/payments');
        $this->post('/payments', $payload)->assertRedirect('/payments');

        $this->assertSame(
            2,
            \App\Models\Payment::query()->where('customer_id', $customer->id)->count(),
            'AUDIT: the web payment-entry route has no idempotency protection — a double-submit/retry creates two payment rows.'
        );
    }

    public function test_recording_a_single_payment_for_a_disconnected_customer_is_rejected(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'disconnected']);

        $this->actingAsRole('manager');

        $response = $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('customer_uuid');
        $this->assertDatabaseMissing('payments', ['customer_id' => $customer->id]);
    }

    public function test_recording_a_single_payment_for_a_suspended_customer_is_rejected(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'suspended']);

        $this->actingAsRole('manager');

        $response = $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('customer_uuid');
        $this->assertDatabaseMissing('payments', ['customer_id' => $customer->id]);
    }

    public function test_recording_a_single_payment_for_a_passive_customer_still_works(): void
    {
        // Explicit regression proof that 'passive' is NOT blocked — only
        // 'disconnected' and 'suspended' are (an explicit product decision;
        // 'passive' may be reused for something else later, out of scope
        // here).
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'passive']);

        $this->actingAsRole('manager');

        $response = $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
        ]);

        $response->assertRedirect('/payments');
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'amount' => 2500,
        ]);
    }

    public function test_bulk_payment_skips_a_disconnected_customer_but_records_for_the_rest(): void
    {
        $zone = ZoneFactory::new()->create();
        $activeA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);
        $disconnected = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 3000, 'status' => 'disconnected']);
        $activeB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 4000, 'status' => 'active']);

        $this->actingAsRole('manager');

        $response = $this->post('/payments/bulk', [
            'customer_uuids' => [$activeA->uuid, $disconnected->uuid, $activeB->uuid],
            'frequency' => 'monthly',
        ]);

        $response->assertRedirect('/payments');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('payments', [
            'customer_id' => $activeA->id,
            'amount' => 2500,
            'verification_status' => 'verified',
        ]);
        $this->assertDatabaseHas('payments', [
            'customer_id' => $activeB->id,
            'amount' => 4000,
            'verification_status' => 'verified',
        ]);
        // The disconnected customer is skipped, not a batch-wide failure.
        $this->assertDatabaseMissing('payments', ['customer_id' => $disconnected->id]);
    }

    public function test_reconnecting_a_disconnected_customer_still_records_the_reconnection_fine_payment(): void
    {
        // The critical regression test for this change. CustomerStatusService
        // ::reconnectOne() calls PaymentService::create() directly (a plain
        // PHP method call, bypassing StorePaymentRequest entirely) to record
        // the reconnection-fine payment WHILE the customer's status is still
        // 'disconnected' — the new payment block lives in the FormRequest/
        // service-loop layer specifically so it never sees, and never
        // interferes with, this call. include_fine is explicitly opted into
        // here (2026-08 owner decision: the fine defaults to false/off).
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'disconnected']);

        $this->actingAsRole('manager');

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

    // -----------------------------------------------------------------
    // Case 1 — the narrow "Secretary" per-user payment-recording grant
    // (PaymentPolicy::create()'s doc comment).
    // -----------------------------------------------------------------

    public function test_worker_without_the_flag_cannot_record_a_payment(): void
    {
        $customer = $this->customer();

        $this->actingAsRole('worker'); // can_record_payments left at its false default

        $response = $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('payments', ['customer_id' => $customer->id]);
    }

    public function test_worker_with_the_flag_can_record_a_payment_for_a_customer_in_their_own_branch(): void
    {
        $branch = BranchFactory::new()->create();
        $zone = ZoneFactory::new()->create(['branch_id' => $branch->id]);
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);

        $this->actingAsWorkerWithPaymentFlag($branch->id);

        $response = $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
        ]);

        $response->assertRedirect('/payments');
        // Not super/admin/manager, so this still enters 'pending' — the
        // flag grants the ABILITY to record, it doesn't auto-verify.
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'amount' => 2500,
            'verification_status' => 'pending',
        ]);
    }

    /**
     * The branch fence explicitly, per the review's must-fix: a flag-granted
     * worker fenced to branch A must NOT be able to record a payment for a
     * customer in branch B — CustomerRepository::findByUuid() (via
     * PaymentService::resolveCustomerId()) is branch-scoped and returns
     * null for an out-of-branch customer, so this surfaces as a
     * customer_uuid validation error, mirroring how BranchScopingTest
     * proves the same fence for a branch-fenced manager elsewhere.
     */
    public function test_worker_with_the_flag_cannot_record_a_payment_for_a_customer_in_another_branch(): void
    {
        $branchA = BranchFactory::new()->create();
        $branchB = BranchFactory::new()->create();
        $zoneA = ZoneFactory::new()->create(['branch_id' => $branchA->id]);
        $zoneB = ZoneFactory::new()->create(['branch_id' => $branchB->id]);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'bill' => 2500, 'status' => 'active']);

        $this->actingAsWorkerWithPaymentFlag($branchA->id);

        $response = $this->post('/payments', [
            'customer_uuid' => $customerB->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('customer_uuid');
        $this->assertDatabaseMissing('payments', ['customer_id' => $customerB->id]);
    }

    /**
     * The PaymentController::create() data-leak fix: the customer picker on
     * GET /payments/create must go through the same branch-scoped
     * repository as everything else — previously it queried Customer::query()
     * directly, so a branch-fenced caller (including this new flag-granted
     * worker case) would see every customer in every branch in the picker,
     * even though the POST itself was already correctly branch-scoped.
     */
    public function test_payment_create_pages_customer_picker_is_branch_scoped(): void
    {
        $branchA = BranchFactory::new()->create();
        $branchB = BranchFactory::new()->create();
        $zoneA = ZoneFactory::new()->create(['branch_id' => $branchA->id]);
        $zoneB = ZoneFactory::new()->create(['branch_id' => $branchB->id]);
        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'name' => 'BRANCH PICKER ALPHA']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'name' => 'BRANCH PICKER BETA']);

        $this->actingAsRole('manager', $branchA->id);

        $response = $this->get('/payments/create');

        $response->assertOk();
        $response->assertInertia(function (Assert $page) use ($customerA, $customerB) {
            $page->component('Payments/Create');
            $uuids = collect($page->toArray()['props']['customers'])->pluck('uuid');
            $this->assertTrue($uuids->contains($customerA->uuid), 'Branch-A customer must be visible.');
            $this->assertFalse($uuids->contains($customerB->uuid), 'Branch-B customer must NOT be visible.');
        });
    }

    // -----------------------------------------------------------------
    // Case 2 — zone-scoped agent verification
    // (PaymentPolicy::verify()'s doc comment).
    // -----------------------------------------------------------------

    public function test_agent_can_verify_a_payment_for_a_customer_in_their_own_zone(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsAgentInZone($zone->id);

        $response = $this->post("/payments/{$payment->uuid}/verify", [
            'action' => 'approve',
            'momo_ref' => 'MOMO-ZONE-001',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'verification_status' => 'verified',
        ]);
    }

    public function test_agent_cannot_verify_a_payment_for_a_customer_in_a_different_zone(): void
    {
        $ownZone = ZoneFactory::new()->create();
        $otherZone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $otherZone->id, 'bill' => 2500, 'status' => 'active']);
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsAgentInZone($ownZone->id);

        $response = $this->post("/payments/{$payment->uuid}/verify", [
            'action' => 'approve',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'verification_status' => 'pending',
        ]);
    }

    // -----------------------------------------------------------------
    // Regression: super/admin/manager are unaffected by either change.
    // -----------------------------------------------------------------

    public function test_manager_bulk_verify_is_unaffected_by_the_agent_zone_fence(): void
    {
        // A manager has no zoneId at all (TenantContext::zoneId is only
        // ever populated for role === 'agent') and must still be able to
        // bulk-verify payments spanning multiple zones in one batch, exactly
        // as before this change.
        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'bill' => 2500, 'status' => 'active']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'bill' => 3000, 'status' => 'active']);
        $paymentA = PaymentFactory::new()->pending()->create(['customer_id' => $customerA->id, 'amount' => 2500]);
        $paymentB = PaymentFactory::new()->pending()->create(['customer_id' => $customerB->id, 'amount' => 3000]);

        $this->actingAsRole('manager');

        $response = $this->post('/payments/bulk-verify', [
            'payment_uuids' => [$paymentA->uuid, $paymentB->uuid],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('payments', ['id' => $paymentA->id, 'verification_status' => 'verified']);
        $this->assertDatabaseHas('payments', ['id' => $paymentB->id, 'verification_status' => 'verified']);
    }

    public function test_super_admin_and_manager_can_still_record_and_verify_payments_normally(): void
    {
        foreach (['super', 'admin', 'manager'] as $role) {
            $customer = $this->customer();

            $this->actingAsRole($role);

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
    }

    // -----------------------------------------------------------------
    // Payment edit — correcting a recorded payment's amount/frequency/
    // months/credit. Distinct action from verify() above (which only
    // approves/rejects a *pending* payment): PaymentPolicy::update() gates
    // this to super/admin/manager only, same as PaymentPolicy's class doc
    // comment's "only admin/super edit" convention.
    // -----------------------------------------------------------------

    public function test_manager_can_edit_a_payments_amount(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500]);

        $this->actingAsRole('manager');

        $response = $this->put("/payments/{$payment->uuid}", [
            'amount' => 3200,
            'frequency' => 'monthly',
        ]);

        $response->assertRedirect("/payments/{$payment->uuid}");
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'amount' => 3200,
        ]);
    }

    public function test_admin_and_super_can_also_edit_a_payment(): void
    {
        foreach (['admin', 'super'] as $role) {
            $customer = $this->customer();
            $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500]);

            $this->actingAsRole($role);

            $response = $this->put("/payments/{$payment->uuid}", [
                'amount' => 4100,
                'frequency' => 'monthly',
            ]);

            $response->assertRedirect("/payments/{$payment->uuid}");
            $this->assertDatabaseHas('payments', [
                'id' => $payment->id,
                'amount' => 4100,
            ]);
        }
    }

    public function test_agent_gets_a_403_attempting_to_edit_a_payment(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500]);

        $this->actingAsRole('agent');

        $response = $this->put("/payments/{$payment->uuid}", [
            'amount' => 9999,
            'frequency' => 'monthly',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'amount' => 2500,
        ]);
    }

    public function test_worker_gets_a_403_attempting_to_edit_a_payment(): void
    {
        // Even the "Secretary" worker+flag grant (PaymentPolicy::create()'s
        // per-user can_record_payments case) does not extend to editing —
        // update() has no such carve-out, unlike create()/attachReceipt().
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500]);

        $this->actingAsWorkerWithPaymentFlag();

        $response = $this->put("/payments/{$payment->uuid}", [
            'amount' => 9999,
            'frequency' => 'monthly',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'amount' => 2500,
        ]);
    }

    public function test_editing_frequency_to_months_recomputes_the_expiration_date(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
            'frequency' => 'monthly',
            'months' => null,
            'expiration_date' => null,
        ]);

        $this->actingAsRole('manager');

        $response = $this->put("/payments/{$payment->uuid}", [
            'amount' => 12500,
            'frequency' => 'months',
            'months' => 5,
        ]);

        $response->assertRedirect("/payments/{$payment->uuid}");
        $payment->refresh();

        $this->assertSame('months', $payment->frequency);
        $this->assertSame(5, $payment->months);
        $this->assertSame(now()->addMonths(5)->toDateString(), $payment->expiration_date->toDateString());
    }

    public function test_edit_page_renders_for_a_manager(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $response = $this->get("/payments/{$payment->uuid}/edit");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Edit')
                ->where('payment.uuid', $payment->uuid));
    }

    public function test_agent_gets_a_403_visiting_the_edit_page(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('agent');

        $response = $this->get("/payments/{$payment->uuid}/edit");

        $response->assertStatus(403);
    }

    /**
     * Split into two independent test methods deliberately — NOT a single
     * test making two sequential requests under different roles. Laravel's
     * Route object memoizes its resolved controller instance
     * (Route::controller()) for the lifetime of the booted application, and
     * within one test method both `$this->get()` calls share that same
     * booted app/router — so a second request under a different role would
     * silently reuse PaymentController's FIRST-resolved TenantContext
     * (constructor-injected), never seeing the role change. That's a test-
     * framework artifact only: every real HTTP request is its own fresh PHP
     * bootstrap in production, so TenantContext is never actually stale
     * there. Each test method here gets its own fresh application (Laravel
     * re-bootstraps per test method), so one-role-per-test sidesteps it.
     */
    public function test_show_page_exposes_can_manage_true_for_a_manager(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $this->get("/payments/{$payment->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('can_manage', true));
    }

    public function test_show_page_exposes_can_manage_false_for_an_agent(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('agent');

        $this->get("/payments/{$payment->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('can_manage', false));
    }

    // -----------------------------------------------------------------
    // Payment delete — permanently removes a recorded payment.
    // PaymentPolicy::delete() gates this to super/admin ONLY, stricter
    // than update()'s super/admin/manager, per the "only admin/super
    // edit or delete" convention documented on that policy's class doc
    // comment.
    // -----------------------------------------------------------------

    public function test_super_can_delete_a_payment(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('super');

        $response = $this->delete("/payments/{$payment->uuid}");

        $response->assertRedirect('/payments');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_admin_can_delete_a_payment(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('admin');

        $response = $this->delete("/payments/{$payment->uuid}");

        $response->assertRedirect('/payments');
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_manager_gets_a_403_attempting_to_delete_a_payment(): void
    {
        // Unlike update(), delete() does NOT extend to manager — the
        // stricter of the two role gates on this policy.
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $response = $this->delete("/payments/{$payment->uuid}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_agent_gets_a_403_attempting_to_delete_a_payment(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('agent');

        $response = $this->delete("/payments/{$payment->uuid}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_worker_gets_a_403_attempting_to_delete_a_payment_even_with_the_payment_recording_flag(): void
    {
        // The narrow "Secretary" per-user can_record_payments grant
        // (PaymentPolicy::create()'s doc comment) does not extend to
        // delete() — same as update() above, delete() has no such
        // carve-out for a flag-granted worker.
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->actingAsWorkerWithPaymentFlag();

        $response = $this->delete("/payments/{$payment->uuid}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    /**
     * Audit scenario: payment_verifications.payment_id is defined with
     * ->cascadeOnDelete() (see that table's migration), so deleting a
     * payment that already has a verification record attached must not
     * raise an FK-constraint violation — the verification row is expected
     * to cascade-delete alongside it at the DB level.
     */
    public function test_deleting_a_verified_payment_cascades_its_verification_record_without_error(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');
        $this->post("/payments/{$payment->uuid}/verify", [
            'action' => 'approve',
            'momo_ref' => 'MOMO-DELETE-001',
        ])->assertRedirect();

        $this->assertDatabaseHas('payment_verifications', ['payment_id' => $payment->id]);

        $this->actingAsRole('super');

        $response = $this->delete("/payments/{$payment->uuid}");

        $response->assertRedirect('/payments');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
        $this->assertDatabaseMissing('payment_verifications', ['payment_id' => $payment->id]);
    }
}
