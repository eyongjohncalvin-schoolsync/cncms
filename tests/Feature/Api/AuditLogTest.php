<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use App\Models\Zone;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Covers App\Observers\AuditableObserver end-to-end (audit-strategy.md
 * sections 2/4) — runs against the real `tenantswecom` schema, see
 * InteractsWithTenantRoles for the transaction/role-switching strategy.
 */
class AuditLogTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    public function test_updating_a_customer_writes_a_diffed_audit_log_row(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/customers/{$customer->uuid}", ['status' => 'disconnected']);

        $response->assertOk();

        $log = AuditLog::query()
            ->where('table_name', 'customers')
            ->where('record_uuid', $customer->uuid)
            ->where('action', 'update')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'updating a customer should write an audit_logs row');
        $this->assertSame('active', $log->old_values['status']);
        $this->assertSame('disconnected', $log->new_values['status']);
    }

    /**
     * Covers App\Repositories\Eloquent\AuditLogRepository::applySearch's
     * primary, name-based way to find an audit trail (replacing the old
     * record_uuid-only filter) — a search by customer name should surface
     * both the customer's own audit event AND events on tables that only
     * reference the customer via customer_id (e.g. payments), since a real
     * user searching "John Doe" wants everything that happened to that
     * customer, not just their own record's row.
     */
    public function test_search_by_customer_name_finds_their_audit_events(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'name' => 'Zzyzx Search Target '.uniqid(),
        ]);
        PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/audit/logs?search='.urlencode('Zzyzx Search Target'));

        $response->assertOk();

        $entries = collect($response->json('data'));

        $this->assertContains(
            $customer->uuid,
            $entries->pluck('record_uuid'),
            "search should find the customer's own create event"
        );
        $this->assertTrue(
            $entries->contains(fn (array $log): bool => $log['table_name'] === 'payments'),
            'search should also find audit events on tables that only reference the customer via customer_id (payments)'
        );
    }

    public function test_search_finds_nothing_for_a_name_that_does_not_exist(): void
    {
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/audit/logs?search='.urlencode('NoSuchPersonExistsInThisTenant12345'));

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    /**
     * The name-based search resolves customers/agents/zones/expense_
     * categories/companies directly against the JSONB old_values/new_values
     * snapshot rather than a live join against the current table (see
     * AuditLogRepository::applySearch's docblock) — specifically so a
     * deleted record's history stays searchable by the name it had at the
     * time, even though the live row is gone.
     */
    public function test_search_finds_a_deleted_customers_audit_history(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'name' => 'Ephemeral Deleted Customer '.uniqid(),
        ]);
        $uuid = $customer->uuid;
        $name = $customer->name;
        $customer->delete();

        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/audit/logs?search='.urlencode($name));

        $response->assertOk();

        $deleteLog = collect($response->json('data'))
            ->first(fn (array $log): bool => $log['record_uuid'] === $uuid && $log['action'] === 'delete');

        $this->assertNotNull(
            $deleteLog,
            "a deleted customer's audit trail should still be findable by the name it had at the time of deletion"
        );
    }

    public function test_super_admin_and_manager_can_search_audit_logs(): void
    {
        $zone = ZoneFactory::new()->create();

        // Each role searches for its OWN customer (distinct name per role,
        // not a shared filter) so each request gets a distinct
        // AuditLogService::list() cache key — reusing one filter set across
        // repeated calls within the 30s cache TTL would hit a pre-existing,
        // unrelated bug where the cached LengthAwarePaginator's collection
        // gets mutated in place by AuditLogResource::collection()'s
        // paginator->through() on the first call, and returns
        // AuditLogResource instances instead of raw AuditLog models on the
        // second — out of scope to fix here.
        foreach (['super', 'admin', 'manager'] as $role) {
            $customer = CustomerFactory::new()->create([
                'zone_id' => $zone->id,
                'name' => "RoleCheck Search Customer {$role} ".uniqid(),
            ]);

            $token = $this->tokenForRole($role);

            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/v1/audit/logs?search='.urlencode($customer->name));

            $response->assertOk();
            $this->assertContains(
                $customer->uuid,
                collect($response->json('data'))->pluck('record_uuid'),
                "role {$role} should be able to search and find the customer's audit event"
            );
        }
    }

    public function test_an_agent_is_forbidden_from_viewing_audit_logs(): void
    {
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/audit/logs');

        $response->assertStatus(403);
    }

    public function test_a_manager_can_view_audit_logs(): void
    {
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/audit/logs');

        $response->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_manuscript_calculate_does_not_crash_and_logs_system_actions_with_a_null_user(): void
    {
        // manuscript:calculate owns its own tenancy()->initialize()/end()
        // lifecycle end-to-end, exactly as ManuscriptCalculateTest's
        // command-level test documents: tenancy()->end() purges the tenant
        // connection, which would silently roll back setUp()'s still-open
        // outer transaction if left in place. So this test releases that
        // empty outer transaction up front and cleans up its own rows
        // explicitly afterwards instead of relying on rollback.
        DB::connection('tenant')->rollBack();
        tenancy()->end();

        tenancy()->initialize(Tenant::find('swecom'));
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);
        tenancy()->end();

        // A period unlikely to collide with any other test's fixtures/CommandRun rows.
        $period = '2031-11';

        try {
            // The command must run to completion (no exception, no observer
            // crash) even though it runs via CLI with no authenticated user
            // — auth()->id() is null in this context.
            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => 'swecom',
            ])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));

            $manuscript = Manuscript::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period)
                ->first();

            $this->assertNotNull($manuscript, 'the command should still have created the manuscript');

            $log = AuditLog::query()
                ->where('table_name', 'manuscripts')
                ->where('record_uuid', $manuscript->uuid)
                ->latest('id')
                ->first();

            $this->assertNotNull($log, 'manuscript:calculate should still write an audit_logs row for the manuscript it created');
            $this->assertSame('create', $log->action);
            $this->assertNull($log->user_id, 'system/scheduled actions have no authenticated user');
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            // manuscript:calculate chunks over EVERY customer in the tenant,
            // not just the one this test created — so the period it stamps
            // ends up on every existing customer's manuscript row, not only
            // ours. Clean up by period (all of them), not by customer_id,
            // or every other real customer in the schema is left with a
            // permanent bogus '2031-11' manuscript row after this test runs.
            Manuscript::query()->where('period', $period)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Customer::query()->whereKey($customer->id)->delete();
            Zone::query()->whereKey($zone->id)->delete();

            tenancy()->end();

            // InteractsWithTenantRoles::initializeTenant() (run in setUp())
            // registered a beforeApplicationDestroyed callback that
            // unconditionally touches the `tenant` connection when this
            // test's Application is torn down. tenancy()->end() above
            // purges that connection entirely, so without re-establishing
            // it here that callback blows up with "Database connection
            // [tenant] not configured." instead of the harmless no-op
            // rollback it expects.
            tenancy()->initialize(Tenant::find('swecom'));
            DB::connection('tenant')->beginTransaction();
        }
    }
}
