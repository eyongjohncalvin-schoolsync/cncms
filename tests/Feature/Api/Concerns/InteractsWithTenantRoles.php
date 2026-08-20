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
     */
    protected function tokenForRole(string $role): string
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        return $user->createToken('api')->plainTextToken;
    }
}
