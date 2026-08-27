<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\User;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Proves the multi-branch RBAC boundary from
 * .ai/skills/cncms/cncms-context/references/branches-and-locations.md
 * section 4/7: a staff member fenced to one branch (tenant_users.branch_id
 * set) can see/access only that branch's rows through
 * Customer/Payment/Agent/Zone/Manuscript — never another branch's, whether
 * via a list endpoint (filtered out) or a direct uuid lookup (404, not
 * silently allowed). Also proves the two "must not regress" defaults: a
 * null branch_id (every existing user, day one) stays fully cross-branch,
 * and an `agent`-role user is scoped transitively via their own Agent
 * row's zone -> branch, independent of tenant_users.branch_id.
 *
 * Uses the same real-`swecom`-tenant + DatabaseTransactions strategy as
 * every other Api feature test (see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles) — no tenant schema
 * create/drop cycles.
 */
class BranchScopingTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        // The 'array' cache store (phpunit.xml's CACHE_STORE) is not
        // guaranteed a fresh empty backing array per test method in this
        // test runner, and several of the Services this test exercises
        // (CustomerService::list(), etc.) cache list results keyed
        // partly by the caller's effective branch fence. Two test methods
        // hitting the exact same route+query-string with the same
        // resolved branchId (most commonly the unrestricted 'all' key)
        // would otherwise share a cache entry across tests — a real
        // flake, not a security bug, but one that would make this
        // specific test file's assertions unreliable. Flushing here
        // guarantees each test method starts cold.
        Cache::flush();
    }

    /**
     * @return array{0: Branch, 1: \App\Models\Zone, 2: Branch, 3: \App\Models\Zone}
     */
    private function twoBranches(): array
    {
        $branchA = BranchFactory::new()->create();
        $branchB = BranchFactory::new()->create();
        $zoneA = ZoneFactory::new()->create(['branch_id' => $branchA->id]);
        $zoneB = ZoneFactory::new()->create(['branch_id' => $branchB->id]);

        return [$branchA, $zoneA, $branchB, $zoneB];
    }

    // -----------------------------------------------------------------
    // Customers
    // -----------------------------------------------------------------

    public function test_branch_fenced_manager_sees_only_their_branch_customers_in_list(): void
    {
        [$branchA, $zoneA, , $zoneB] = $this->twoBranches();

        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id]);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id]);

        $token = $this->tokenForRole('manager', $branchA->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/customers?per_page=100');

        $response->assertOk();
        $uuids = collect($response->json('data'))->pluck('uuid');

        $this->assertTrue($uuids->contains($customerA->uuid), 'Branch-A customer must be visible.');
        $this->assertFalse($uuids->contains($customerB->uuid), 'Branch-B customer must NOT be visible.');
    }

    public function test_branch_fenced_manager_cannot_directly_access_other_branch_customer(): void
    {
        [$branchA, , , $zoneB] = $this->twoBranches();

        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id]);

        $token = $this->tokenForRole('manager', $branchA->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/customers/{$customerB->uuid}");

        $response->assertStatus(404);
    }

    public function test_null_branch_manager_sees_customers_from_both_branches(): void
    {
        [, $zoneA, , $zoneB] = $this->twoBranches();

        // Named distinctively and located via the `search` filter rather
        // than "list everything and check per_page" — the real swecom
        // tenant already has ~549 pre-existing customers, so an
        // unrestricted (both-branches) result set is far larger than any
        // single page; searching pins down exactly these two rows
        // regardless of how much real seed data exists alongside them.
        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'name' => 'BRANCH SCOPE TEST ALPHA']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'name' => 'BRANCH SCOPE TEST BETA']);

        $token = $this->tokenForRole('manager'); // branch_id stays null

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/customers?search=BRANCH+SCOPE+TEST&per_page=50');

        $response->assertOk();
        $uuids = collect($response->json('data'))->pluck('uuid');

        $this->assertTrue($uuids->contains($customerA->uuid));
        $this->assertTrue($uuids->contains($customerB->uuid));
    }

    // -----------------------------------------------------------------
    // Payments
    // -----------------------------------------------------------------

    public function test_branch_fenced_manager_sees_only_their_branch_payments_in_list(): void
    {
        [$branchA, $zoneA, , $zoneB] = $this->twoBranches();

        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id]);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id]);
        $paymentA = PaymentFactory::new()->create(['customer_id' => $customerA->id]);
        $paymentB = PaymentFactory::new()->create(['customer_id' => $customerB->id]);

        $token = $this->tokenForRole('manager', $branchA->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/payments?per_page=100');

        $response->assertOk();
        $uuids = collect($response->json('data'))->pluck('uuid');

        $this->assertTrue($uuids->contains($paymentA->uuid));
        $this->assertFalse($uuids->contains($paymentB->uuid));
    }

    public function test_branch_fenced_manager_cannot_directly_access_other_branch_payment(): void
    {
        [$branchA, , , $zoneB] = $this->twoBranches();

        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id]);
        $paymentB = PaymentFactory::new()->create(['customer_id' => $customerB->id]);

        $token = $this->tokenForRole('manager', $branchA->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments/{$paymentB->uuid}");

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Agents
    // -----------------------------------------------------------------

    public function test_branch_fenced_manager_sees_only_their_branch_agents_in_list(): void
    {
        [$branchA, $zoneA, , $zoneB] = $this->twoBranches();

        // user_id: null — see AgentTest.php's class doc comment: a fresh
        // factory-created central user isn't yet visible to the tenant
        // connection's session within this same transaction.
        $agentA = AgentFactory::new()->create(['zone_id' => $zoneA->id, 'user_id' => null]);
        $agentB = AgentFactory::new()->create(['zone_id' => $zoneB->id, 'user_id' => null]);

        $token = $this->tokenForRole('manager', $branchA->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/agents?per_page=100');

        $response->assertOk();
        $uuids = collect($response->json('data'))->pluck('uuid');

        $this->assertTrue($uuids->contains($agentA->uuid));
        $this->assertFalse($uuids->contains($agentB->uuid));
    }

    public function test_branch_fenced_manager_cannot_directly_access_other_branch_agent(): void
    {
        [$branchA, , , $zoneB] = $this->twoBranches();

        $agentB = AgentFactory::new()->create(['zone_id' => $zoneB->id, 'user_id' => null]);

        $token = $this->tokenForRole('manager', $branchA->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/agents/{$agentB->uuid}");

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Zones
    // -----------------------------------------------------------------

    public function test_branch_fenced_manager_sees_only_their_branch_zones_in_list(): void
    {
        [$branchA, $zoneA, , $zoneB] = $this->twoBranches();

        $token = $this->tokenForRole('manager', $branchA->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/zones?per_page=100');

        $response->assertOk();
        $uuids = collect($response->json('data'))->pluck('uuid');

        $this->assertTrue($uuids->contains($zoneA->uuid));
        $this->assertFalse($uuids->contains($zoneB->uuid));
    }

    public function test_branch_fenced_manager_cannot_directly_access_other_branch_zone(): void
    {
        [$branchA, , , $zoneB] = $this->twoBranches();

        $token = $this->tokenForRole('manager', $branchA->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/zones/{$zoneB->uuid}");

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Manuscripts
    // -----------------------------------------------------------------

    public function test_branch_fenced_manager_sees_only_their_branch_manuscripts_in_list(): void
    {
        [$branchA, $zoneA, , $zoneB] = $this->twoBranches();

        $period = now()->format('Y-m');
        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id]);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customerA->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customerB->id]);

        $token = $this->tokenForRole('manager', $branchA->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/manuscripts?period={$period}&per_page=100");

        $response->assertOk();
        $customerUuids = collect($response->json('data'))->pluck('customer_uuid');

        $this->assertTrue($customerUuids->contains($customerA->uuid));
        $this->assertFalse($customerUuids->contains($customerB->uuid));
    }

    // -----------------------------------------------------------------
    // Agent-role zone-derived scoping (independent of tenant_users.branch_id)
    // -----------------------------------------------------------------

    public function test_agent_role_is_scoped_via_their_own_zones_branch_not_tenant_user_branch_id(): void
    {
        [, $zoneA, , $zoneB] = $this->twoBranches();

        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id]);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id]);

        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        // tokenForRole flips role to 'agent' with tenant_users.branch_id
        // left null — deliberately NOT set, per branches-and-locations.md
        // section 4: an agent's fence must come entirely from their own
        // Agent row's zone, never from tenant_users.branch_id. user_id
        // points at the real, already-committed seeded owner (not a fresh
        // factory-created central user) for the same cross-connection-
        // visibility reason InteractsWithTenantRoles documents.
        $token = $this->tokenForRole('agent');

        AgentFactory::new()->create(['zone_id' => $zoneA->id, 'user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/customers?per_page=200');

        $response->assertOk();
        $uuids = collect($response->json('data'))->pluck('uuid');

        $this->assertTrue($uuids->contains($customerA->uuid), "Agent's own zone (branch A) customer must be visible.");
        $this->assertFalse($uuids->contains($customerB->uuid), 'Branch-B customer must NOT be visible to a branch-A-zoned agent.');
    }

    public function test_agent_role_without_an_agent_row_falls_back_to_tenant_user_branch_id(): void
    {
        // No Agent row created for this user at all — TenantContext::resolve()
        // must fall back to tenant_users.branch_id (left null here) rather
        // than crash or wrongly fence everything out. Matches every
        // pre-existing "agent" test in CustomerTest.php, which never create
        // an Agent row either.
        [, $zoneA, , $zoneB] = $this->twoBranches();

        // See test_null_branch_manager_sees_customers_from_both_branches()'s
        // comment on why `search` is used instead of a large per_page: the
        // real swecom tenant already has ~549 pre-existing customers.
        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'name' => 'BRANCH SCOPE TEST GAMMA']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'name' => 'BRANCH SCOPE TEST DELTA']);

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/customers?search=BRANCH+SCOPE+TEST&per_page=50');

        $response->assertOk();
        $uuids = collect($response->json('data'))->pluck('uuid');

        $this->assertTrue($uuids->contains($customerA->uuid));
        $this->assertTrue($uuids->contains($customerB->uuid));
    }
}
