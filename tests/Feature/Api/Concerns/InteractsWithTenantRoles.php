<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Concerns;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shared setup for API feature tests: initializes tenancy to the real
 * "swecom" tenant (manually transacting the dynamically-created `tenant`
 * connection, mirroring tests/Feature/Api/AuthTest.php and
 * tests/Feature/ManuscriptCalculateTest.php), and provides a way to
 * authenticate as any of the five tenant roles without ever needing to
 * insert a brand-new central `users` row.
 *
 * Why reuse the seeded owner instead of creating a fresh User + TenantUser
 * pair per role: TenantUser lives on the separate `tenant` Postgres
 * connection (a distinct session from `pgsql`), so a freshly-created
 * central User row — still uncommitted inside this test's `pgsql`
 * transaction — is not yet visible to that session, and inserting a
 * TenantUser row referencing it would trip the cross-schema foreign key
 * (tenant_users_user_id_foreign -> public.users). Reusing the already-
 * committed seeded owner (kelvin@shalomtech.dev) and simply flipping their
 * *role* for the seeded tenant_users row sidesteps that gap entirely — the
 * update is a same-connection, same-transaction write that rolls back with
 * everything else when the test ends.
 */
trait InteractsWithTenantRoles
{
    protected function initializeTenant(): void
    {
        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant, 'The swecom tenant must already be provisioned to run this test.');

        tenancy()->initialize($tenant);

        DB::connection('tenant')->beginTransaction();

        $this->beforeApplicationDestroyed(function () {
            if (DB::connection('tenant')->transactionLevel() > 0) {
                DB::connection('tenant')->rollBack();
            }
        });
    }

    /**
     * Authenticates as the real seeded owner (kelvin@shalomtech.dev),
     * with their tenant_users role temporarily switched to $role for the
     * rest of this test. Returns the Bearer Authorization header value.
     *
     * $branchId optionally also sets tenant_users.branch_id for the same
     * seeded owner row — the multi-branch RBAC fence (see
     * .ai/skills/cncms/cncms-context/references/branches-and-locations.md
     * section 4 and App\Support\TenantContext::resolve()). Omitted/null
     * clears it back to the default "unrestricted" (sees every branch),
     * matching what every existing caller of this method already implies.
     * This mirrors the exact same "flip a column on the one committed,
     * reusable owner row" strategy as $role above, for the same
     * cross-connection-visibility reason documented on this trait.
     */
    protected function tokenForRole(string $role, ?int $branchId = null): string
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);

        return $user->createToken('api')->plainTextToken;
    }
}
