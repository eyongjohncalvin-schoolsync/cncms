<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Zone;
use App\Services\CustomerStatusService;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end proof for
 * .claude/skills/cncms-context/references/prepaid-pause-handling.md — the
 * date arithmetic App\Services\CustomerStatusService performs on
 * `manuscripts.payment_expiration` (via the customer's `latestManuscript`)
 * when a customer is reconnected from `disconnected` or from `suspended`
 * with `prepaid_paused = true`.
 *
 * Mirrors ManuscriptCalculateTest's setUp()/tearDown() pattern (a real,
 * manually-managed transaction on the dynamically-created `tenant`
 * connection, rolled back in tearDown()) rather than
 * CustomerReconnectArrearsPaymentTest's explicit-cleanup-in-finally
 * pattern: none of the tests here run the `manuscript:calculate` artisan
 * command (no tenancy()->end()/initialize() cycling mid-test), so there's
 * no risk of Stancl silently dropping an open transaction's fixtures — the
 * simpler wrap-in-a-transaction approach is safe and leaves the real
 * swecom schema completely untouched even if a test fails partway through.
 *
 * Every scenario below drives Carbon::setTestNow() explicitly so the
 * "freeze duration" arithmetic can be asserted against exact, known dates
 * — not just "some later date" — matching the task's requirement for real
 * numeric (date) evidence.
 */
class PrepaidPausePreservationTest extends TestCase
{
    use DatabaseTransactions;

    private CustomerStatusService $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant, 'The swecom tenant must already be provisioned to run this test.');

        tenancy()->initialize($tenant);

        DB::connection('tenant')->beginTransaction();

        // Stands in for the tenant role a real 'manager' request would carry
        // (see ResolveTenantWeb) — needed for CustomerStatusService's
        // PaymentService dependency to resolve at all, even though none of
        // the scenarios below actually record a fine/arrears payment (see
        // CustomerReconnectArrearsPaymentTest's identical setup).
        $this->app->instance(TenantContext::class, new TenantContext(new TenantUser, 'manager'));

        $this->statuses = app(CustomerStatusService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (tenancy()->initialized) {
            if (DB::connection('tenant')->transactionLevel() > 0) {
                DB::connection('tenant')->rollBack();
            }

            tenancy()->end();
        }

        parent::tearDown();
    }

    private function zone(): Zone
    {
        return ZoneFactory::new()->create();
    }

    private function activeCustomer(): Customer
    {
        return CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);
    }

    private function manuscriptWithExpiration(Customer $customer, string $expiration, string $period = '2031-01'): Manuscript
    {
        return ManuscriptFactory::new()->create([
            'customer_id' => $customer->id,
            'period' => $period,
            'payment_expiration' => $expiration,
        ]);
    }

    /**
     * Core scenario #1: 2 months of prepaid time left, suspended (pause
     * chosen) for exactly 3 months, then reconnected.
     */
    public function test_suspend_with_pause_chosen_extends_payment_expiration_by_the_exact_suspension_duration(): void
    {
        $customer = $this->activeCustomer();
        $this->manuscriptWithExpiration($customer, '2026-03-01');

        Carbon::setTestNow('2026-01-01 00:00:00'); // 2 months of prepaid time remain
        $this->statuses->suspend($customer, 'customer_request', null, pausePrepaid: true);

        $customer->refresh();
        $this->assertSame('suspended', $customer->status);
        $this->assertTrue($customer->prepaid_paused, 'prepaid_paused must be recorded true for the "pause" choice.');
        $this->assertSame('2026-01-01 00:00:00', $customer->status_changed_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-04-01 00:00:00'); // exactly 3 months later
        $this->statuses->reconnect($customer->fresh());

        $customer->refresh();
        $this->assertSame('active', $customer->status);
        $this->assertNull($customer->prepaid_paused, 'the one-suspension-cycle flag must be cleared on reconnect.');

        // 2026-03-01 + 3 months (the exact suspension duration) = 2026-06-01,
        // NOT left at the original 2026-03-01 date.
        $this->assertSame('2026-06-01', $customer->latestManuscript->fresh()->payment_expiration->format('Y-m-d'));
    }

    /**
     * Core scenario #2: the same setup, but "continue" chosen instead —
     * payment_expiration must be left completely untouched.
     */
    public function test_suspend_with_continue_chosen_leaves_payment_expiration_untouched(): void
    {
        $customer = $this->activeCustomer();
        $this->manuscriptWithExpiration($customer, '2026-03-01');

        Carbon::setTestNow('2026-01-01 00:00:00');
        $this->statuses->suspend($customer, 'customer_request', null, pausePrepaid: false);

        $customer->refresh();
        // Recorded as an explicit `false` (not null) — there WAS an active
        // window, so the admin's "continue" choice is meaningful and is
        // exactly what reconnectOne() reads to decide NOT to extend.
        $this->assertFalse($customer->prepaid_paused, 'prepaid_paused must be explicitly false for the "continue" choice on an active window.');

        Carbon::setTestNow('2026-04-01 00:00:00'); // 3 months later
        $this->statuses->reconnect($customer->fresh());

        $customer->refresh();
        $this->assertSame('active', $customer->status);

        // Untouched — still the original date, today's plain carry-forward
        // behavior, which is CORRECT for the explicit "continue" choice.
        $this->assertSame('2026-03-01', $customer->latestManuscript->fresh()->payment_expiration->format('Y-m-d'));
    }

    /**
     * Core scenario #3: disconnect always extends, unconditionally — no
     * choice is ever offered or consulted.
     */
    public function test_disconnect_always_extends_payment_expiration_with_no_choice_offered(): void
    {
        $customer = $this->activeCustomer();
        $this->manuscriptWithExpiration($customer, '2026-03-01');

        Carbon::setTestNow('2026-01-01 00:00:00');
        $this->statuses->disconnect($customer);

        $customer->refresh();
        $this->assertSame('disconnected', $customer->status);
        $this->assertNull($customer->prepaid_paused, 'disconnect never sets prepaid_paused — it is a suspend-only, one-cycle flag.');

        Carbon::setTestNow('2026-04-01 00:00:00'); // exactly 3 months later
        $this->statuses->reconnect($customer->fresh());

        $customer->refresh();
        $this->assertSame('active', $customer->status);
        $this->assertSame('2026-06-01', $customer->latestManuscript->fresh()->payment_expiration->format('Y-m-d'));
    }

    /**
     * A customer with NO active prepaid window at all: suspend/disconnect
     * must proceed exactly as before this feature — no `prepaid_paused`
     * recorded, and reconnect must not crash or fabricate a
     * `payment_expiration` out of nothing.
     */
    public function test_a_customer_with_no_prepaid_window_is_unaffected_by_suspend_disconnect_reconnect(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $suspendedCustomer = $this->activeCustomer(); // no manuscript at all
        $this->statuses->suspend($suspendedCustomer, 'tv_problem', null, pausePrepaid: true);

        $suspendedCustomer->refresh();
        $this->assertNull($suspendedCustomer->prepaid_paused, 'nothing active to pause — the admin\'s choice must be moot, not recorded.');
        $this->assertNotNull($suspendedCustomer->status_changed_at);

        $disconnectedCustomer = $this->activeCustomer(); // also no manuscript
        $this->statuses->disconnect($disconnectedCustomer);
        $disconnectedCustomer->refresh();
        $this->assertNull($disconnectedCustomer->prepaid_paused);

        Carbon::setTestNow('2026-02-15 00:00:00');

        $this->statuses->reconnect($suspendedCustomer->fresh());
        $this->statuses->reconnect($disconnectedCustomer->fresh());

        $suspendedCustomer->refresh();
        $disconnectedCustomer->refresh();

        $this->assertSame('active', $suspendedCustomer->status);
        $this->assertSame('active', $disconnectedCustomer->status);
        $this->assertNull($suspendedCustomer->latestManuscript, 'no manuscript must be fabricated where none existed.');
        $this->assertNull($disconnectedCustomer->latestManuscript, 'no manuscript must be fabricated where none existed.');
    }

    /**
     * A customer whose payment_expiration had ALREADY lapsed by the time
     * they were suspended: the suspend-time choice must be treated as moot
     * (nothing "active" to pause), matching the no-window case above even
     * though `payment_expiration` is technically still set on the row.
     */
    public function test_an_already_lapsed_payment_expiration_at_suspend_time_does_not_record_prepaid_paused(): void
    {
        $customer = $this->activeCustomer();
        $this->manuscriptWithExpiration($customer, '2025-12-01'); // in the past relative to the suspend below

        Carbon::setTestNow('2026-01-01 00:00:00');
        $this->statuses->suspend($customer, 'tv_problem', null, pausePrepaid: true);

        $customer->refresh();
        $this->assertNull($customer->prepaid_paused, 'an already-lapsed expiration is not an "active" window — nothing to pause.');
    }

    /**
     * Multiple independent suspend->reconnect->disconnect->reconnect
     * cycles: each cycle's `status_changed_at` is fresh, so each extension
     * is computed purely from its OWN cycle's duration — proven here by
     * summing two different-length freezes (1 month, then 2 months) onto
     * the same original expiration and checking the final date reflects
     * exactly 3 months of total extension, not a stale/compounding
     * miscalculation.
     */
    public function test_sequential_suspend_reconnect_disconnect_reconnect_cycles_each_compute_their_own_duration(): void
    {
        $customer = $this->activeCustomer();
        $this->manuscriptWithExpiration($customer, '2026-06-01');

        // Cycle 1: suspend (pause) for exactly 1 month.
        Carbon::setTestNow('2026-01-01 00:00:00');
        $this->statuses->suspend($customer, 'customer_request', null, pausePrepaid: true);

        Carbon::setTestNow('2026-02-01 00:00:00');
        $this->statuses->reconnect($customer->fresh());

        $customer->refresh();
        $afterCycle1 = $customer->latestManuscript->fresh()->payment_expiration->format('Y-m-d');
        $this->assertSame('2026-07-01', $afterCycle1, 'cycle 1: 2026-06-01 + 1 month.');
        $this->assertNull($customer->prepaid_paused);

        // Cycle 2: disconnect for exactly 2 months — a completely
        // independent freeze, started fresh from an 'active' status.
        Carbon::setTestNow('2026-03-01 00:00:00');
        $this->statuses->disconnect($customer);

        Carbon::setTestNow('2026-05-01 00:00:00');
        $this->statuses->reconnect($customer->fresh());

        $customer->refresh();
        // 2026-07-01 (cycle 1's result) + 2 months = 2026-09-01. If cycle 2
        // had incorrectly reused cycle 1's status_changed_at (2026-01-01)
        // instead of its own fresh one (2026-03-01), this would instead
        // come out to 2026-07-01 + 4 months = 2026-11-01 — a different,
        // wrong number, so this assertion genuinely distinguishes correct
        // from incorrect duration bookkeeping.
        $this->assertSame('2026-09-01', $customer->latestManuscript->fresh()->payment_expiration->format('Y-m-d'));
    }
}
