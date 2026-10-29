<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Remittance;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Money must balance at every level, wave 5 — the Money balance page
 * (owner, 2026-10-28): one branch's month, where every franc collected went,
 * the checks proving the chain adds up, and each zone's collections against
 * its bills. Tests run in a month nothing else touches (March 2027).
 */
class MoneyBalanceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private const string PERIOD = '2027-03';

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Company::query()->update(['cash_controls_start_date' => '2026-09-01']);
        Cache::forget(Company::CACHE_KEY);
        $this->travelTo(Carbon::parse('2027-03-10 09:00', 'Africa/Lagos'));
    }

    private function as(string $email, string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }

    private function customer(?Zone $zone = null): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ($zone ?? ZoneFactory::new()->create())->id, 'bill' => 2500, 'status' => 'active']);
    }

    /** The collector records 2 × 2 500 cash and 5 000 MTN MoMo, then hands the cash over. */
    private function collectAndHandOver(): Remittance
    {
        $collector = $this->as('kelvin@shalomtech.dev', 'manager');
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 5000, 'frequency' => 'monthly', 'method' => 'mtn_momo', 'method_reference' => 'MB-1'])
            ->assertSessionHasNoErrors();
        $this->post('/cash/remittances', ['declared_cash' => '5000'])->assertSessionHasNoErrors();

        return Remittance::query()->where('collector_user_id', $collector->id)->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function checks(array $checks): array
    {
        return collect($checks)->pluck('status', 'key')->all();
    }

    public function test_a_month_where_everything_was_handed_in_balances(): void
    {
        $remittance = $this->collectAndHandOver();

        $this->as('patience@shalomtech.dev', 'cashier');
        $this->post("/cash/remittances/{$remittance->uuid}/receive", [
            'received_cash' => '5000',
            'confirmed_lines' => [$remittance->lines()->where('method', 'mtn_momo')->sole()->id],
        ])->assertSessionHasNoErrors();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/cash/balance?period='.self::PERIOD)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Cash/Balance')
            ->where('collections.totals.recorded', '10000.00')
            ->where('collections.totals.handed_in', '10000.00')
            ->where('collections.totals.with_collector', '0.00')
            ->where('handovers.received', '5000.00')
            ->where('handovers.into_tills', '5000.00')
            ->where('checks', fn ($checks) => $this->checks($checks->all())['collections_handed_in'] === 'ok'
                && $this->checks($checks->all())['collections_found'] === 'ok'
                && $this->checks($checks->all())['handovers_into_tills'] === 'ok'
                && $this->checks($checks->all())['shortages_settled'] === 'ok'));
    }

    public function test_money_not_handed_in_not_found_or_short_is_shown_where_it_is(): void
    {
        $remittance = $this->collectAndHandOver();
        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 3000, 'frequency' => 'monthly'])->assertSessionHasNoErrors();

        // 1 000 short, and the MoMo line not found on the statement.
        $this->as('patience@shalomtech.dev', 'cashier');
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => '4000'])->assertSessionHasNoErrors();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/cash/balance?period='.self::PERIOD)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.totals.recorded', '13000.00')
            ->where('collections.totals.handed_in', '5000.00')
            ->where('collections.totals.not_confirmed', '5000.00')
            ->where('collections.totals.with_collector', '3000.00')
            ->where('handovers.short', '1000.00')
            ->where('handovers.short_open', '1000.00')
            ->where('shortages.open', '1000.00')
            ->where('checks', fn ($checks) => $this->checks($checks->all())['collections_handed_in'] === 'warning'
                && $this->checks($checks->all())['collections_found'] === 'danger'
                && $this->checks($checks->all())['shortages_settled'] === 'danger'
                && $this->checks($checks->all())['handovers_into_tills'] === 'ok'));
    }

    public function test_a_zone_collecting_well_below_its_usual_rate_stands_out(): void
    {
        $zone = ZoneFactory::new()->create(['name' => 'Fiango Test']);
        $customer = $this->customer($zone);

        foreach (['2026-12' => 10000, '2027-01' => 10000, '2027-02' => 9000, '2027-03' => 3000] as $period => $paid) {
            ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'bill' => 10000]);
            PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => $paid, 'created_at' => Carbon::parse("{$period}-05 10:00", 'Africa/Lagos')]);
        }

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/cash/balance?period='.self::PERIOD)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('zones', function ($zones) use ($zone): bool {
                $row = collect($zones)->firstWhere('uuid', $zone->uuid);

                return $row['rate'] === 30 && $row['usual_rate'] === 96.7 && $row['dropped'] === true;
            })
            ->where('checks', fn ($checks) => $this->checks($checks->all())['zones_steady'] === 'warning'));
    }

    public function test_a_fenced_manager_sees_only_their_branch(): void
    {
        $buea = BranchFactory::new()->create(['name' => 'Buea']);
        $bueaZone = ZoneFactory::new()->create(['branch_id' => $buea->id]);
        PaymentFactory::new()->create(['customer_id' => $this->customer($bueaZone)->id, 'amount' => 7000]);
        $headquarters = Branch::query()->where('is_headquarters', true)->sole();

        $this->as('terence@shalomtech.dev', 'manager', $headquarters->id);
        $this->get('/cash/balance?period='.self::PERIOD)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.totals.recorded', '0.00')
            ->where('zones', fn ($zones) => ! collect($zones)->pluck('uuid')->contains($bueaZone->uuid)));

        $this->as('terence@shalomtech.dev', 'manager', $buea->id);
        $this->get('/cash/balance?period='.self::PERIOD)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.totals.recorded', '7000.00'));
    }

    public function test_only_staff_who_see_the_cash_book_open_it_and_a_future_month_falls_back(): void
    {
        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->get('/cash/balance')->assertForbidden();

        $this->as('terence@shalomtech.dev', 'manager');
        $this->get('/cash/balance?period=2099-01')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('period', self::PERIOD)
            ->where('is_current', true)
            ->where('next_period', null)
            ->where('previous_period', '2027-02'));
    }
}
