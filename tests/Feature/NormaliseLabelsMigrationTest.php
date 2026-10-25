<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The 2026_10_25 normalise-labels tenant migration, re-run inside this test's
 * tenant transaction (rolled back afterwards, functional indexes included)
 * against rows written raw so they bypass the model's normalisation.
 */
class NormaliseLabelsMigrationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function runMigration(): void
    {
        (require database_path('migrations/tenant/2026_10_25_025019_normalise_labels_to_upper_case.php'))->up();
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))->where('indexname', $name)->exists();
    }

    public function test_colliding_zone_names_are_left_alone_and_the_index_is_skipped(): void
    {
        DB::statement('DROP INDEX IF EXISTS zones_name_upper_unique');
        $branchId = DB::table('branches')->value('id');
        DB::table('zones')->insert([
            ['branch_id' => $branchId, 'name' => 'Zmig-Col'],
            ['branch_id' => $branchId, 'name' => 'ZMIG-COL'],
            ['branch_id' => $branchId, 'name' => '  zmig   solo é '],
        ]);

        $this->runMigration();

        $this->assertEqualsCanonicalizing(['ZMIG-COL', 'Zmig-Col'], DB::table('zones')->whereRaw('upper(name) = ?', ['ZMIG-COL'])->pluck('name')->all());
        $this->assertTrue(DB::table('zones')->where('name', 'ZMIG SOLO É')->exists());
        $this->assertFalse($this->hasIndex('zones_name_upper_unique'));
    }

    public function test_without_collisions_names_are_upper_cased_and_the_index_is_added(): void
    {
        DB::statement('DROP INDEX IF EXISTS branches_name_upper_unique');
        DB::table('branches')->insert(['name' => ' bmig  limbé ']);

        $this->runMigration();

        $this->assertTrue(DB::table('branches')->where('name', 'BMIG LIMBÉ')->exists());
        $this->assertTrue($this->hasIndex('branches_name_upper_unique'));
    }

    public function test_customer_names_and_locations_are_upper_cased_including_archived_ones(): void
    {
        $zone = ZoneFactory::new()->create();
        $active = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        $archived = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        $homonym = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        DB::table('customers')->where('id', $active->id)->update(['name' => ' cmig  jane é ', 'location' => 'molyko  road', 'email' => 'Jane@Example.com']);
        DB::table('customers')->where('id', $archived->id)->update(['name' => 'cmig archived', 'location' => null, 'deleted_at' => now()]);
        DB::table('customers')->where('id', $homonym->id)->update(['name' => 'CMIG Jane é']);
        $auditRows = DB::table('audit_logs')->count();

        $this->runMigration();

        $this->assertDatabaseHas('customers', ['id' => $active->id, 'name' => 'CMIG JANE É', 'location' => 'MOLYKO ROAD', 'email' => 'Jane@Example.com']);
        $this->assertDatabaseHas('customers', ['id' => $archived->id, 'name' => 'CMIG ARCHIVED', 'location' => null]);
        $this->assertDatabaseHas('customers', ['id' => $homonym->id, 'name' => 'CMIG JANE É']);
        $this->assertSame($auditRows, DB::table('audit_logs')->count(), 'no audit rows');
    }
}
