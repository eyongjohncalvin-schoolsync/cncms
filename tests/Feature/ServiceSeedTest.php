<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * services.md section 3 (2026-09-03 addendum) — a brand-new tenant's `tv`
 * service seeds at 2,500 FCFA (SWECOM's real base rate), not 0.00, so the
 * office never has to remember to go set it before adding their first
 * customer. Internet/VOD/Satellite Hosting still seed at 0.00 — there's no
 * comparable obvious default for those.
 *
 * Uses a real disposable tenant (not just calling the seeder in-process)
 * specifically to prove the actual `tenants:migrate` provisioning path a
 * brand-new tenant goes through produces this — the same reasoning
 * RolePermissionResolutionTest uses for the roles seed.
 */
class ServiceSeedTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDisposableTenant;

    public function test_a_freshly_provisioned_tenant_seeds_tv_at_2500_and_others_at_zero(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }

        $tenant = $this->provisionDisposableTenant('svcseed');

        tenancy()->initialize($tenant);

        $prices = Service::query()->pluck('price', 'slug');

        $this->assertSame('2500.00', (string) $prices['tv']);
        $this->assertSame('0.00', (string) $prices['internet']);
        $this->assertSame('0.00', (string) $prices['vod']);
        $this->assertSame('0.00', (string) $prices['satellite-hosting']);

        tenancy()->end();

        Tenant::find($tenant->id)?->delete();

        if (DB::connection()->transactionLevel() === 0) {
            DB::connection()->beginTransaction();
        }
    }
}
