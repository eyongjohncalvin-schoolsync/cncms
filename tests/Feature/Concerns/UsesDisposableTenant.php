<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Provisions a real, throwaway tenant SCHEMA for tests that must commit
 * real, durably-visible fixture data outside the normal
 * DatabaseTransactions/RefreshDatabase safety net every other feature test
 * in this suite uses.
 *
 * Why any test needs this at all: Stancl's DatabaseManager::
 * connectToTenant() unconditionally purges and recreates the `tenant` PDO
 * connection on every tenancy()->initialize() call — including the ones
 * Stancl's QueueTenancyBootstrapper triggers automatically for EVERY queued
 * job, even under QUEUE_CONNECTION=sync. That purge silently disconnects/
 * rolls back an open outer transaction's uncommitted fixtures before a
 * queued chunk job ever gets to read them, so a test exercising a real
 * Bus::batch() chunked job (or anything else that re-initializes tenancy
 * mid-test) cannot rely on the usual "wrap it in a transaction and roll
 * back" trick. See ManuscriptGenerationBatchServiceTest's class doc for the
 * fullest version of this reasoning.
 *
 * 2026-08-27 incident this closes: this codebase used to solve that problem
 * by committing real fixtures straight into the live `swecom` tenant schema
 * and relying on a bare `finally { ... }` block to manually delete them
 * again. A killed test process, a timeout, or an exception thrown mid-
 * cleanup skipped that `finally` entirely — which is exactly what happened:
 * 1,509 bogus manuscript rows for nonsense future periods were left
 * committed against all 446 real swecom customers, with zero trace in
 * command_runs (proving it bypassed every normal tracked path).
 *
 * The fix: never write real fixtures into `swecom` (or any other real
 * tenant) for this pattern again. Provision a brand-new, uniquely-named
 * tenant per test method instead, exactly like tests/Feature/Web/
 * LandlordTest.php's test_store_provisions_a_working_tenant already does
 * (proven-safe, pre-existing pattern in this codebase — not a new
 * mechanism invented for this fix):
 *
 *   - Tenant::create() runs Stancl's CreateDatabase -> MigrateDatabase ->
 *     SeedDatabase pipeline SYNCHRONOUSLY (TenancyServiceProvider pins
 *     shouldBeQueued(false) on TenantCreated), so the schema is fully
 *     migrated and ready to use the moment create() returns.
 *   - $tenant->delete() runs Stancl's DeleteDatabase pipeline
 *     synchronously too — a real `DROP SCHEMA "..." CASCADE` on Postgres
 *     (see Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager),
 *     which unconditionally wipes every row this test created (manuscripts,
 *     payments, customers, zones, command_runs, arrears_adjustments —
 *     everything), regardless of what state the test left them in.
 *
 * THE SAFETY PROPERTY THIS BUYS: every fixture a test using this trait
 * commits lives in a schema unique to that one test invocation, never in
 * `swecom` or any other real tenant. If the test process is killed, times
 * out, or crashes mid-test — exactly the scenario that produced the
 * incident above — the worst case is now an orphaned `tenant_<id>` schema
 * sitting unused in the test database (harmless clutter, needs occasional
 * manual pruning — see task-scheduler.md's 2026-08-27 addendum), never
 * corrupted real customer data. Unlike the old finally-block cleanup, this
 * safety property does NOT depend on any cleanup code actually running: the
 * data was never in a real tenant to begin with.
 */
trait UsesDisposableTenant
{
    /**
     * @param  string  $prefix  short, test-file-specific tag (letters only)
     *                          so an orphaned schema left behind by a killed
     *                          run is easy to trace back to whichever test
     *                          file created it. Combined with a timestamp
     *                          and a random suffix so concurrent/repeated
     *                          runs never collide with each other or with a
     *                          schema an earlier interrupted run left
     *                          behind.
     */
    private function provisionDisposableTenant(string $prefix): Tenant
    {
        $id = $prefix.now()->format('YmdHis').random_int(1000, 9999);

        return Tenant::create([
            'id' => $id,
            'name' => "Disposable test tenant ({$prefix})",
            'slug' => $id,
        ]);
    }

    /**
     * TenantDatabaseSeeder (see its own docblock) seeds only reference data
     * (zones/expense categories/company) — no admin user — so a test that
     * needs to authenticate against a disposable tenant has to create one
     * itself. That's less trivial than it looks: User/TenantUser live on two
     * genuinely separate Postgres SESSIONS (the central `pgsql` connection
     * vs. the `tenant` connection Stancl swaps in), so a User row still
     * sitting inside this test's own uncommitted DatabaseTransactions
     * wrapper on `pgsql` is invisible to the `tenant` session's FK check
     * when inserting the matching `tenant_users` row — the exact gap
     * Tests\Feature\Api\Concerns\InteractsWithTenantRoles::tokenForRole()'s
     * docblock documents (and sidesteps by reusing an already-committed
     * seeded user instead of creating a fresh one).
     *
     * A disposable tenant has no such pre-existing user to reuse, so this
     * method takes the other valid way out: commit the central connection's
     * transaction for real (making the new User row genuinely visible
     * cross-session), then re-open an empty one before returning, exactly
     * mirroring how every caller of provisionDisposableTenant() already
     * leaves the `tenant` connection — `DB::connection('tenant')->
     * beginTransaction()` — as a harmless no-op for DatabaseTransactions'
     * own teardown to roll back. The real User row this creates is
     * committed and durable, so callers MUST delete it themselves once
     * done (see this method's own return value) — dropping the disposable
     * tenant's schema in tearDown() only removes the `tenant_users` row
     * (schema-local); the central `users` row lives outside that schema
     * entirely and would otherwise leak into every future test run.
     */
    private function provisionDisposableTenantAdmin(Tenant $tenant, string $role = 'admin'): User
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }

        $user = User::factory()->create();

        DB::connection()->beginTransaction();

        tenancy()->initialize($tenant);

        TenantUser::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => $role,
        ]);

        tenancy()->end();

        return $user;
    }
}
