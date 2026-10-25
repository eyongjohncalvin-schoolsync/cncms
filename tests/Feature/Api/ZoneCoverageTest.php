<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\DataTransferObjects\AgentData;
use App\DataTransferObjects\ZoneData;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Models\ZoneAgent;
use App\Services\AgentService;
use App\Services\ManuscriptService;
use App\Services\ZoneCoverageService;
use App\Services\ZoneService;
use App\Support\TenantContext;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Which zones a field agent covers (App\Models\ZoneAgent): one, several or
 * all — management decides. An agent sees, syncs, collects and verifies in
 * the zones they cover and nowhere else; a zone has one responsible
 * collector, printed on its bills.
 */
class ZoneCoverageTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Zone $zoneA;

    private Zone $zoneB;

    private Zone $zoneC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $headquarters = Branch::headquartersId();
        [$this->zoneA, $this->zoneB, $this->zoneC] = [
            ZoneFactory::new()->create(['name' => 'COVERAGE A', 'branch_id' => $headquarters]),
            ZoneFactory::new()->create(['name' => 'COVERAGE B', 'branch_id' => $headquarters]),
            ZoneFactory::new()->create(['name' => 'COVERAGE C', 'branch_id' => $headquarters]),
        ];
    }

    public function test_a_new_agent_is_responsible_for_their_zone_and_the_next_one_helps(): void
    {
        $first = AgentFactory::new()->create(['zone_id' => $this->zoneA->id, 'user_id' => null]);
        $second = AgentFactory::new()->create(['zone_id' => $this->zoneA->id, 'user_id' => null]);

        $this->assertSame(ZoneAgent::ROLE_RESPONSIBLE, $this->roleOf($first, $this->zoneA));
        $this->assertSame(ZoneAgent::ROLE_HELPER, $this->roleOf($second, $this->zoneA));
    }

    public function test_the_database_allows_one_responsible_collector_per_zone(): void
    {
        AgentFactory::new()->create(['zone_id' => $this->zoneA->id, 'user_id' => null]);
        $second = AgentFactory::new()->create(['zone_id' => $this->zoneA->id, 'user_id' => null]);

        $this->expectException(QueryException::class);

        DB::connection('tenant')->transaction(fn () => ZoneAgent::query()->withoutGlobalScope(BranchScope::class)
            ->where('agent_id', $second->id)->update(['role' => ZoneAgent::ROLE_RESPONSIBLE]));
    }

    public function test_an_agent_covers_zones_of_their_own_branch_only(): void
    {
        $agent = AgentFactory::new()->create(['zone_id' => $this->zoneA->id, 'user_id' => null]);
        $bueaZone = ZoneFactory::new()->create(['branch_id' => BranchFactory::new()->create(['name' => 'BUEA'])->id]);

        try {
            app(ZoneCoverageService::class)->addZone($agent, $bueaZone, null);
            $this->fail('A zone of another branch must be refused.');
        } catch (ValidationException) {
            // Expected.
        }

        // And the database refuses it even past the service.
        $this->expectException(QueryException::class);
        DB::connection('tenant')->table('zone_agents')->insert([
            'zone_id' => $bueaZone->id, 'agent_id' => $agent->id, 'branch_id' => $agent->branch_id,
            'role' => ZoneAgent::ROLE_HELPER, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_agent_syncs_the_customers_of_every_zone_they_cover_and_no_other(): void
    {
        $inA = $this->customerIn($this->zoneA);
        $inB = $this->customerIn($this->zoneB);
        $inC = $this->customerIn($this->zoneC);

        $token = $this->tokenForAgentCovering($this->zoneA, $this->zoneB);
        $uuids = $this->pulledCustomerUuids($token);

        $this->assertContains($inA->uuid, $uuids);
        $this->assertContains($inB->uuid, $uuids);
        $this->assertNotContains($inC->uuid, $uuids);
    }

    public function test_a_zone_given_since_the_last_sync_sends_all_its_customers(): void
    {
        $inB = $this->customerIn($this->zoneB);
        Customer::query()->whereKey($inB->id)->update(['updated_at' => now()->subDays(10)]);
        $since = now()->subDay()->toIso8601String();

        $token = $this->tokenForAgentCovering($this->zoneA, $this->zoneB);

        $this->assertContains($inB->uuid, $this->pulledCustomerUuids($token, $since));
    }

    public function test_an_agent_covering_nothing_sees_nothing(): void
    {
        $inA = $this->customerIn($this->zoneA);

        // No Agent row at all.
        $this->assertNotContains($inA->uuid, $this->pulledCustomerUuids($this->tokenForRole('agent')), 'no agent row');

        // An Agent row, but no zones.
        AgentFactory::new()->withoutCoverage()->create(['zone_id' => $this->zoneA->id, 'user_id' => $this->ownerId()]);
        $this->assertNotContains($inA->uuid, $this->pulledCustomerUuids($this->tokenForRole('agent')), 'agent row, no zones');
    }

    public function test_an_agent_acts_on_customers_in_covered_zones_only(): void
    {
        $inB = $this->customerIn($this->zoneB);
        $inC = $this->customerIn($this->zoneC);
        $this->tokenForAgentCovering($this->zoneA, $this->zoneB);

        $context = TenantContext::resolve(TenantUser::query()->where('user_id', $this->ownerId())->firstOrFail());
        app()->instance(TenantContext::class, $context);

        $this->assertSame([$this->zoneA->id, $this->zoneB->id], $context->zoneIds);
        $owner = User::query()->findOrFail($this->ownerId());
        $this->assertTrue(Gate::forUser($owner)->allows('disconnect', $inB));
        $this->assertFalse(Gate::forUser($owner)->allows('disconnect', $inC));
    }

    public function test_bills_carry_the_zones_responsible_collector(): void
    {
        $collector = AgentFactory::new()->create(['zone_id' => $this->zoneA->id, 'user_id' => null, 'name' => 'DIVINE NJI']);
        AgentFactory::new()->create(['zone_id' => $this->zoneA->id, 'user_id' => null, 'name' => 'A HELPER']);
        $inA = $this->customerIn($this->zoneA);
        $inC = $this->customerIn($this->zoneC);
        $period = now()->format('Y-m');
        ManuscriptFactory::new()->create(['customer_id' => $inA->id, 'period' => $period]);
        ManuscriptFactory::new()->create(['customer_id' => $inC->id, 'period' => $period]);

        $bills = collect(app(ManuscriptService::class)->billDataForCustomers(
            Customer::query()->with(['zone', 'latestManuscript'])->whereIn('id', [$inA->id, $inC->id])->get(),
            $period,
        ))->keyBy(fn (array $bill): int => $bill['customer']->id);

        $this->assertSame($collector->name, $bills[$inA->id]['collector_name']);
        $this->assertNull($bills[$inC->id]['collector_name'], 'A zone with no responsible collector prints none.');
    }

    public function test_moving_an_agent_to_another_branch_moves_their_coverage(): void
    {
        $this->actingAs(User::query()->findOrFail($this->ownerId()));
        $agent = AgentFactory::new()->create(['zone_id' => $this->zoneA->id, 'user_id' => null]);
        app(ZoneCoverageService::class)->addZone($agent, $this->zoneB, null);
        $bueaZone = ZoneFactory::new()->create(['branch_id' => BranchFactory::new()->create(['name' => 'BUEA'])->id]);

        app(AgentService::class)->update($agent, AgentData::fromArray(['zone_uuid' => $bueaZone->uuid]));

        $this->assertSame(
            [$bueaZone->id],
            ZoneAgent::query()->withoutGlobalScope(BranchScope::class)->where('agent_id', $agent->id)->pluck('zone_id')->all(),
        );
        $this->assertSame($bueaZone->branch_id, $agent->fresh()->branch_id);
    }

    public function test_a_covered_zone_keeps_its_branch(): void
    {
        $this->actingAs(User::query()->findOrFail($this->ownerId()));
        $agent = AgentFactory::new()->create(['zone_id' => $this->zoneA->id, 'user_id' => null]);
        app(ZoneCoverageService::class)->addZone($agent, $this->zoneB, null);
        $buea = BranchFactory::new()->create(['name' => 'BUEA']);

        $this->expectException(ValidationException::class);

        app(ZoneService::class)->update($this->zoneB, ZoneData::fromArray(['branch_uuid' => $buea->uuid]));
    }

    private function roleOf(Agent $agent, Zone $zone): ?string
    {
        return ZoneAgent::query()->withoutGlobalScope(BranchScope::class)->where('agent_id', $agent->id)->where('zone_id', $zone->id)->value('role');
    }

    private function customerIn(Zone $zone): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);
    }

    /**
     * @return list<string>
     */
    private function pulledCustomerUuids(string $token, ?string $since = null): array
    {
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/sync/pull'.($since !== null ? '?since='.urlencode($since) : ''))
            ->assertOk();
        app()->forgetInstance(TenantContext::class);

        return collect($response->json('changes.customers.upserted'))->pluck('uuid')->all();
    }

    private function ownerId(): int
    {
        return (int) User::query()->where('email', 'kelvin@shalomtech.dev')->value('id');
    }
}
