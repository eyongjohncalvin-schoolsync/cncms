<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantDatabaseSeeder extends Seeder
{
    /**
     * Seed a freshly provisioned tenant schema with reference/lookup data.
     *
     * This is the class referenced by config/tenancy.php's `seeder_parameters`, so it
     * runs against the tenant's own schema (search_path already scoped by tenancy) when
     * `php artisan tenants:seed` executes.
     *
     * Only reference data is seeded here: zones, expense categories, and the single
     * company record. Transactional data (customers, payments, manuscripts, etc.) is
     * intentionally out of scope for this pass.
     */
    public function run(): void
    {
        $this->seedZones();
        $this->seedExpenseCategories();
        $this->seedCompany();

        // RBAC v2 system roles (super/admin/manager/agent/worker) with their
        // day-1 permission sets. Idempotent — see DefaultRolesSeeder. Also
        // seeded by the 2026_09_02_000100 migration for already-provisioned
        // tenants; running both is harmless.
        $this->call(DefaultRolesSeeder::class);
    }

    private function seedZones(): void
    {
        // Full zone list — all 29 zones belong to KUMBA 3.
        $names = [
            'THR01 (3/CORNERS)', 'TR 03', 'AR01(JUNCTION)', 'AR02(HANS)', 'AR03(SEC)',
            'AR04', 'AR 05 (BELMONT)', 'GARE 1', 'LR R01 (LADUMA ROAD)', 'LR R02',
            'M R01 (DODO)', 'M R02', 'M R03 (OIL MILL)', 'M R04 (JERUME)', 'MA R01',
            'MA R02(ELDER)', 'T R01', 'T R02 (BAPTIST)', 'T R03 (BENJI)', 'T R04 (weldering)',
            'T R05(BELTHA)', 'T R06(UNCLE ETA)', 'T R07(school)', 'TRANSMITTER', 'MAHOLE 6',
            'NDIFANG', 'BI', 'TANCHA SCHOOL', 'BANGA FARM',
        ];

        $now = now();
        $branchId = $this->mainBranchId();

        DB::table('zones')->insert(array_map(static fn (string $name): array => [
            'branch_id' => $branchId,
            'name' => $name,
            'town' => 'KUMBA 3',
            'created_at' => $now,
            'updated_at' => $now,
        ], $names));
    }

    /**
     * `zones.branch_id` is NOT NULL (see
     * database/migrations/tenant/2026_08_24_160010_add_branch_id_to_zones_table.php),
     * and the "Main Branch" row this seeder's zones/company belong to is
     * seeded by the branches table's own creation migration
     * (2026_08_24_160000_create_branches_table.php), which always runs
     * before seeding in the tenant provisioning pipeline.
     */
    private function mainBranchId(): int
    {
        return (int) DB::table('branches')->where('name', 'Main Branch')->value('id');
    }

    private function seedExpenseCategories(): void
    {
        $categories = [
            ['name' => 'Staff & Labour', 'icon' => 'ti-users'],
            ['name' => 'Field Operations', 'icon' => 'ti-truck'],
            ['name' => 'Network Maintenance', 'icon' => 'ti-tool'],
            ['name' => 'Office & Admin', 'icon' => 'ti-building'],
            ['name' => 'Utilities', 'icon' => 'ti-bolt'],
            ['name' => 'MOMO Transaction Fees', 'icon' => 'ti-credit-card'],
            ['name' => 'Broadcaster / Signal Fees', 'icon' => 'ti-antenna'],
            ['name' => 'Equipment Purchase', 'icon' => 'ti-device-tv'],
            ['name' => 'Miscellaneous', 'icon' => 'ti-dots'],
        ];

        $now = now();

        DB::table('expense_categories')->insert(array_map(
            static fn (array $category, int $index): array => [
                'name' => $category['name'],
                'icon' => $category['icon'],
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $categories,
            array_keys($categories)
        ));
    }

    private function seedCompany(): void
    {
        $now = now();

        DB::table('companies')->insert([
            'branch_id' => $this->mainBranchId(),
            'name' => 'SWECOM PLC',
            'location' => '3/CORNERS',
            'email' => 'shalomtech@gmail.com',
            'phone' => '676876509/672528022',
            'tech_number' => null,
            'momo_number' => '676876509/672528022',
            'momo_name' => 'MUNGWAN HANS/KELVIN MEKUME',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
