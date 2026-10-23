<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FinalizeWorkspaceProvisioning;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Seeders\SwecomReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * A new workspace starts clean — no SWECOM zones, no SWECOM company
 * details — and tenants:clear-seeded-defaults repairs workspaces created
 * before that fix. Runs on its own disposable tenant (never swecom).
 */
class TenantProvisioningDefaultsTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('tdef');
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        $this->tenant->delete();

        parent::tearDown();
    }

    public function test_a_new_workspace_has_no_zones_and_a_blank_company_named_after_itself(): void
    {
        tenancy()->initialize($this->tenant);

        $this->assertSame(0, DB::table('zones')->count());

        $company = Company::query()->sole();
        $this->assertSame(mb_substr($this->tenant->name, 0, 50), $company->name);
        $this->assertSame('', $company->location);
        $this->assertSame('', $company->phone);
        $this->assertNull($company->email);
        $this->assertNull($company->momo_number);
        $this->assertNull($company->momo_name);

        // Generic reference data is still there.
        $this->assertGreaterThan(0, DB::table('expense_categories')->count());
    }

    public function test_registration_details_are_written_exactly_and_an_empty_momo_stays_empty(): void
    {
        $owner = User::factory()->create(['email' => 'owner-'.$this->tenant->id.'@example.test']);

        try {
            $this->tenant->update([
                'pending_owner_id' => $owner->id,
                'pending_company' => [
                    'company_name' => 'Acme Cable',
                    'company_location' => 'Buea',
                    'company_phone' => '699000000',
                    'momo_number' => '',
                    'momo_name' => null,
                ],
            ]);

            (new FinalizeWorkspaceProvisioning($this->tenant->fresh()))->handle();

            tenancy()->initialize($this->tenant);
            $company = Company::query()->sole();
            $this->assertSame('Acme Cable', $company->name);
            $this->assertSame('Buea', $company->location);
            $this->assertSame('699000000', $company->phone);
            $this->assertSame($owner->email, $company->email);
            $this->assertNull($company->momo_number);
            $this->assertNull($company->momo_name);
        } finally {
            tenancy()->end();
            DB::connection('pgsql')->table('tenant_user_index')->where('user_id', $owner->id)->delete();
            $owner->delete();
        }
    }

    public function test_the_cleanup_command_removes_unused_swecom_zones_and_clears_swecom_details(): void
    {
        tenancy()->initialize($this->tenant);
        (new SwecomReferenceDataSeeder)->run(); // the old provisioning's leftovers
        $usedZone = DB::table('zones')->where('name', 'GARE 1')->value('id');
        CustomerFactory::new()->create(['zone_id' => $usedZone]);
        tenancy()->end();

        $this->artisan('tenants:clear-seeded-defaults', ['tenant' => $this->tenant->id])->assertSuccessful();

        tenancy()->initialize($this->tenant);
        $this->assertSame(29, DB::table('zones')->count(), 'a dry run changes nothing');
        tenancy()->end();

        $this->artisan('tenants:clear-seeded-defaults', ['tenant' => $this->tenant->id, '--force' => true])->assertSuccessful();

        tenancy()->initialize($this->tenant);
        $this->assertSame(['GARE 1'], DB::table('zones')->pluck('name')->all(), 'a zone with customers is kept');
        $company = Company::query()->sole();
        $this->assertSame(mb_substr($this->tenant->name, 0, 50), $company->name);
        $this->assertNull($company->momo_number);
        $this->assertNull($company->email);
        $this->assertSame('', $company->phone);
    }

    public function test_the_cleanup_command_never_touches_swecom(): void
    {
        $this->artisan('tenants:clear-seeded-defaults', ['tenant' => 'swecom', '--force' => true])->assertFailed();
    }
}
