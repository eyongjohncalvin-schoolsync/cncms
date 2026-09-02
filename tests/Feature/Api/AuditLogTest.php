<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Manuscript;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\Feature\Concerns\UsesDisposableTenant;
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
    use UsesDisposableTenant;

    /**
     * test_manuscript_calculate_does_not_crash_and_logs_system_actions_with_a_null_user
     * invokes the real manuscript:calculate command and provisions its own
     * disposable tenant instead of touching real swecom at all — see
     * tests/Feature/Web/ManuscriptTest.php's identical DISPOSABLE_TENANT_TESTS
     * for the full reasoning (2026-08-28, closing this file's own instance
     * of the same incident class documented in task-scheduler.md).
     */
    private const array DISPOSABLE_TENANT_TESTS = [
        'test_manuscript_calculate_does_not_crash_and_logs_system_actions_with_a_null_user',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array($this->name(), self::DISPOSABLE_TENANT_TESTS, true)) {
            $this->initializeTenant();
        }
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
        // Customer uses SoftDeletes now (customer-deletion deliberation) —
        // a plain ->delete() archives rather than removes, and
        // AuditableObserver deliberately does not write a 'delete' audit
        // row for a soft delete. A genuine hard removal is forceDelete(),
        // which still logs 'delete' exactly as before.
        $customer->forceDelete();

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
        // DatabaseTransactions wraps this test's default (central `pgsql`)
        // connection in an outer, uncommitted transaction — but
        // provisionDisposableTenant()'s CREATE SCHEMA runs on that same
        // connection, and the migration step right after runs on the
        // separate `tenant` session, which cannot see an uncommitted DDL
        // change from a different Postgres session. Committing for real
        // first makes the new schema actually visible cross-session. See
        // tests/Feature/Web/ManuscriptTest.php's identical comment.
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }

        $tenant = $this->provisionDisposableTenant('adlt');

        tenancy()->initialize($tenant);
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);
        tenancy()->end();

        $period = Carbon::now()->format('Y-m');

        try {
            // The command must run to completion (no exception, no observer
            // crash) even though it runs via CLI with no authenticated user
            // — auth()->id() is null in this context.
            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => $tenant->id,
            ])->assertExitCode(0);

            tenancy()->initialize($tenant);

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
            tenancy()->end();
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $tenant->delete();

            if (DB::connection()->transactionLevel() === 0) {
                DB::connection()->beginTransaction();
            }
        }
    }
}
