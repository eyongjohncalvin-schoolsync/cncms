<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * GET /manuscripts/pre-run-review — App\Http\Controllers\ManuscriptController::
 * preRunReview(), backed by App\Services\ManuscriptPreRunReviewService.
 * Exercises each of the four ALL-of exclusion rules individually (see that
 * service's class doc), and confirms rule 2 goes through the exact same
 * Payment::scopeEligibleForPeriod() predicate a real manuscript:calculate run
 * uses — not a drifted "paid sometime this month" approximation.
 */
class ManuscriptPreRunReviewTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private const PERIOD = '2026-08';

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        Carbon::setTestNow('2026-08-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    public function test_a_manager_cannot_view_the_pre_run_review_list(): void
    {
        $this->actingAsRole('manager');

        $this->get('/manuscripts/pre-run-review?period='.self::PERIOD)->assertForbidden();
    }

    public function test_an_admin_can_view_the_pre_run_review_list(): void
    {
        $this->actingAsRole('admin');

        $this->get('/manuscripts/pre-run-review?period='.self::PERIOD)->assertOk();
    }

    /**
     * The core scenario: one customer excluded by each of the three
     * exclusion rules, plus one genuinely flagged customer, all in the same
     * response — the flagged list must contain EXACTLY the one customer that
     * fails all three exclusions.
     */
    public function test_each_exclusion_rule_is_applied_individually_and_the_genuinely_unpaid_customer_is_flagged(): void
    {
        $this->actingAsRole('admin');

        $zone = ZoneFactory::new()->create();

        // Excluded by rule 2: an eligible verified payment for THIS period.
        $paidCustomer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        PaymentFactory::new()->create([
            'customer_id' => $paidCustomer->id,
            'amount' => 1000,
            'verification_status' => 'verified',
            'processed_period' => null,
        ]);

        // Excluded by rule 3: an active prepaid window (payment_expiration in the future).
        $prepaidCustomer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        ManuscriptFactory::new()->forPeriod('2026-07')->create([
            'customer_id' => $prepaidCustomer->id,
            'bill' => 2500,
            'total_arrears' => 0,
            'credit' => 0,
            'total_bill' => 0,
            'payment_expiration' => '2026-09-15',
        ]);

        // Excluded by rule 4: credit already covers (equals) the current bill.
        $creditedCustomer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        ManuscriptFactory::new()->forPeriod('2026-07')->create([
            'customer_id' => $creditedCustomer->id,
            'bill' => 2500,
            'total_arrears' => 0,
            'credit' => 2500,
            'total_bill' => 0,
            'payment_expiration' => null,
        ]);

        // Genuinely flagged: active, no eligible payment, no prepaid window, no covering credit.
        $flaggedCustomer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 3000]);

        // Not active — must never appear regardless of anything else (rule 1).
        $disconnectedCustomer = CustomerFactory::new()->disconnected()->create(['zone_id' => $zone->id, 'bill' => 5000]);

        $response = $this->get('/manuscripts/pre-run-review?period='.self::PERIOD);
        $response->assertOk();

        $uuids = array_column($response->json('customers'), 'uuid');

        $this->assertNotContains($paidCustomer->uuid, $uuids, 'a customer with an eligible verified payment must be excluded.');
        $this->assertNotContains($prepaidCustomer->uuid, $uuids, 'a customer inside an active prepaid window must be excluded.');
        $this->assertNotContains($creditedCustomer->uuid, $uuids, 'a customer whose credit already covers their bill must be excluded.');
        $this->assertNotContains($disconnectedCustomer->uuid, $uuids, 'a non-active customer must never be flagged.');
        $this->assertContains($flaggedCustomer->uuid, $uuids, 'a genuinely unpaid active customer must be flagged.');

        $flaggedRow = collect($response->json('customers'))->firstWhere('uuid', $flaggedCustomer->uuid);
        $this->assertSame($flaggedCustomer->name, $flaggedRow['name']);
        $this->assertSame($zone->uuid, $flaggedRow['zone_uuid']);
        $this->assertSame('3000.00', $flaggedRow['bill']);
        $this->assertNull($flaggedRow['last_payment_date']);

        $this->assertSame(self::PERIOD, $response->json('period'));
    }

    /**
     * Confirms rule 2 uses the SAME predicate a real run consumes
     * (Payment::scopeEligibleForPeriod()), not `payments.created_at` falling
     * in the current calendar month: a payment already consumed by a
     * DIFFERENT period (processed_period set to some other period) does NOT
     * count as eligible for THIS period, even though it was recorded
     * recently — so this customer must still be flagged. Likewise a payment
     * that's merely 'pending' (not yet verified) must not count either.
     */
    public function test_only_a_verified_payment_still_eligible_for_this_exact_period_excludes_a_customer(): void
    {
        $this->actingAsRole('admin');

        $zone = ZoneFactory::new()->create();

        $consumedByOtherPeriod = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        PaymentFactory::new()->create([
            'customer_id' => $consumedByOtherPeriod->id,
            'amount' => 2500,
            'verification_status' => 'verified',
            'processed_period' => '2026-07', // already consumed by a DIFFERENT period
        ]);

        $unverifiedPayer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        PaymentFactory::new()->pending()->create([
            'customer_id' => $unverifiedPayer->id,
            'amount' => 2500,
            'processed_period' => null,
        ]);

        $response = $this->get('/manuscripts/pre-run-review?period='.self::PERIOD);
        $response->assertOk();

        $uuids = array_column($response->json('customers'), 'uuid');

        $this->assertContains($consumedByOtherPeriod->uuid, $uuids, "a payment already consumed by a different period must not exclude this period's flag.");
        $this->assertContains($unverifiedPayer->uuid, $uuids, 'an unverified (pending) payment must not exclude a customer.');
    }

    public function test_summary_count_and_total_exposure_sum_only_the_flagged_customers_bills(): void
    {
        $this->actingAsRole('admin');

        $zone = ZoneFactory::new()->create();

        $flaggedA = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2000]);
        $flaggedB = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 3500]);

        $paidCustomer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 9999]);
        PaymentFactory::new()->create([
            'customer_id' => $paidCustomer->id,
            'amount' => 9999,
            'verification_status' => 'verified',
            'processed_period' => null,
        ]);

        $response = $this->get('/manuscripts/pre-run-review?period='.self::PERIOD);
        $response->assertOk();

        $uuids = array_column($response->json('customers'), 'uuid');
        $this->assertContains($flaggedA->uuid, $uuids);
        $this->assertContains($flaggedB->uuid, $uuids);
        $this->assertNotContains($paidCustomer->uuid, $uuids);

        $flaggedCount = collect($response->json('customers'))
            ->whereIn('uuid', [$flaggedA->uuid, $flaggedB->uuid])
            ->count();
        $this->assertSame(2, $flaggedCount);

        // total_exposure sums every currently-flagged customer's bill — only
        // assert the two known fixtures' contribution is present, since
        // other real seeded customers in this tenant may also legitimately
        // be flagged and contribute to the same total.
        $summary = $response->json('summary');
        $this->assertGreaterThanOrEqual(5500.0, (float) $summary['total_exposure']);
        $this->assertGreaterThanOrEqual(2, $summary['count']);
    }

    public function test_period_must_be_valid_and_not_in_the_future(): void
    {
        $this->actingAsRole('admin');

        $this->get('/manuscripts/pre-run-review?period=not-a-period')->assertStatus(422);
        $this->get('/manuscripts/pre-run-review?period=2099-01')->assertStatus(422);
    }

    /**
     * GET /manuscripts/pre-run-review/full — ManuscriptController::
     * preRunReviewFull(), the large-count "Review full list" companion
     * page (task-scheduler.md's 2026-08-27 stage 3 addendum). Same
     * flagging rule as the JSON endpoint above, rendered as a real,
     * paginated Inertia page instead.
     */
    public function test_an_admin_can_view_the_full_pre_run_review_board(): void
    {
        $this->actingAsRole('admin');

        // Scoped to a dedicated, freshly-created zone (via zone_uuid) so this
        // test's single fixture isn't pushed off page 1 by this tenant's
        // real seeded customers, which may also legitimately be flagged for
        // this period — same isolation technique
        // tests/Feature/Web/ManuscriptTest.php's summary tests already use.
        $zone = ZoneFactory::new()->create();
        $flagged = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 3000]);

        $response = $this->get('/manuscripts/pre-run-review/full?period='.self::PERIOD.'&zone_uuid='.$zone->uuid);

        $response->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Manuscripts/PreRunReviewList')
                ->where('period', self::PERIOD)
                ->has('summary.count')
                ->has('customers.data')
                ->has('customers.meta')
                ->has('zones'));

        $page = json_decode(json_encode($response->viewData('page')), true);
        $uuids = array_column($page['props']['customers']['data'], 'uuid');
        $this->assertContains($flagged->uuid, $uuids);
    }

    public function test_a_manager_cannot_view_the_full_pre_run_review_board(): void
    {
        $this->actingAsRole('manager');

        $this->get('/manuscripts/pre-run-review/full?period='.self::PERIOD)->assertForbidden();
    }

    public function test_the_full_board_can_be_filtered_by_zone(): void
    {
        $this->actingAsRole('admin');

        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();
        $inZoneA = CustomerFactory::new()->active()->create(['zone_id' => $zoneA->id, 'bill' => 3000]);
        $inZoneB = CustomerFactory::new()->active()->create(['zone_id' => $zoneB->id, 'bill' => 3000]);

        $response = $this->get('/manuscripts/pre-run-review/full?period='.self::PERIOD.'&zone_uuid='.$zoneA->uuid);
        $response->assertOk();

        $page = json_decode(json_encode($response->viewData('page')), true);
        $uuids = array_column($page['props']['customers']['data'], 'uuid');
        $this->assertContains($inZoneA->uuid, $uuids);
        $this->assertNotContains($inZoneB->uuid, $uuids);
    }
}
