<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * How each zone collects (owner, 2026-10-29) — App\Services\ZonePerformanceService:
 * fair mid-month (earlier months counted to the same day), the share of
 * customers who paid beside the money (a prepayment can't hide customers who
 * stopped paying), on the dashboard, the cash alerts and the agent's phone.
 * Runs on 10 March 2027, a month nothing else touches.
 */
class ZonePerformanceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->travelTo(Carbon::parse('2027-03-10 09:00', 'Africa/Lagos'));
    }

    private function as(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }

    /**
     * A zone of $customers customers billed 2 500 a month from December to
     * March; $payments lists, per month, each customer's payment as [amount, day].
     *
     * @param  array<string, list<array{0: int, 1: int}|null>>  $payments
     */
    private function zone(string $name, int $customers, array $payments): Zone
    {
        $zone = ZoneFactory::new()->create(['name' => $name]);

        for ($i = 0; $i < $customers; $i++) {
            $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);

            foreach ($payments as $period => $perCustomer) {
                ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'bill' => 2500]);

                if (($perCustomer[$i] ?? null) !== null) {
                    [$amount, $day] = $perCustomer[$i];
                    PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => $amount, 'created_at' => Carbon::parse(sprintf('%s-%02d 10:00', $period, $day), 'Africa/Lagos')]);
                }
            }
        }

        return $zone;
    }

    /** Every customer pays on the 5th, December to February; in March only the first pays — 10 000 for four months. */
    private function prepaidZone(string $name): Zone
    {
        $everyone = [[2500, 5], [2500, 5], [2500, 5], [2500, 5]];

        return $this->zone($name, 4, ['2026-12' => $everyone, '2027-01' => $everyone, '2027-02' => $everyone, '2027-03' => [[10000, 5]]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $zones, Zone $zone): array
    {
        return collect($zones)->firstWhere('uuid', $zone->uuid);
    }

    public function test_earlier_months_are_counted_only_to_the_same_day(): void
    {
        // Earlier months paid on the 25th: by the 10th they had nothing in yet.
        $late = [[2500, 25]];
        $zone = $this->zone('Pace Test', 1, ['2026-12' => $late, '2027-01' => $late, '2027-02' => $late, '2027-03' => [[2500, 4]]]);

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/cash/balance')->assertOk()->assertInertia(fn (Assert $page) => $page->where('zones', function ($zones) use ($zone): bool {
            $row = $this->row($zones->all(), $zone);

            // Ahead of the usual 0% by the 10th — and whole earlier months show fully paid on the trend.
            return $row['rate'] === 100 && $row['usual_rate'] === 0 && $row['dropped'] === false
                && collect($row['trend'])->pluck('rate')->slice(2)->values()->all() === [100, 100, 100, 100];
        }));
    }

    public function test_a_prepayment_cannot_hide_customers_who_stopped_paying(): void
    {
        $zone = $this->prepaidZone('Prepay Test');

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/cash/balance')->assertOk()->assertInertia(fn (Assert $page) => $page->where('zones', function ($zones) use ($zone): bool {
            $row = $this->row($zones->all(), $zone);

            return $row['rate'] === 100 && $row['paid_share'] === 25 && $row['usual_paid_share'] === 100
                && [$row['customers_paid'], $row['customers_billed']] === [1, 4] && $row['dropped'] === true;
        }));
    }

    public function test_the_dashboard_and_the_alerts_show_a_zone_below_usual(): void
    {
        $zone = $this->prepaidZone('Dashboard Test');

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('zone_performance.judged', true)
                ->where('zone_performance.dropped', fn ($dropped) => collect($dropped)->pluck('uuid')->contains($zone->uuid))
                ->where('zone_performance.dropped', fn ($dropped) => ! array_key_exists('trend', collect($dropped)->first()))));

        $this->get('/cash/alerts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('alerts', fn ($alerts) => collect(collect($alerts)->firstWhere('key', 'zones_below_usual')['items'] ?? [])
                ->contains(fn ($item) => str_starts_with(strtoupper($item['label']), 'DASHBOARD TEST'))));
    }

    public function test_nothing_is_judged_in_the_first_days_of_the_month(): void
    {
        $this->travelTo(Carbon::parse('2027-03-03 09:00', 'Africa/Lagos'));
        $everyone = [[2500, 2], [2500, 2]];
        $this->zone('Early Test', 2, ['2026-12' => $everyone, '2027-01' => $everyone, '2027-02' => $everyone, '2027-03' => []]);

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/cash/alerts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('alerts', fn ($alerts) => collect($alerts)->firstWhere('key', 'zones_below_usual') === null));
    }

    public function test_an_agent_sees_only_their_own_zones_on_the_phone(): void
    {
        $mine = $this->prepaidZone('Mine Test');
        $this->prepaidZone('Other Test');

        $token = $this->tokenForAgentCovering($mine);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/zones/performance')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $mine->uuid)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.dropped', true)
            ->assertJsonPath('meta.judged', true);
    }
}
