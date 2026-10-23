<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantDatabaseSeeder extends Seeder
{
    /**
     * Seed a freshly provisioned tenant schema with GENERIC reference data.
     *
     * This is the class referenced by config/tenancy.php's `seeder_parameters`, so it
     * runs against the tenant's own schema (search_path already scoped by tenancy) for
     * every new workspace — self-service registrations and landlord "Add Tenant" alike.
     *
     * Nothing operator-specific belongs here: a new company enters its own zones,
     * contact and MoMo details. It used to seed SWECOM's 29 Kumba zones and SWECOM's
     * company details into every workspace; those now live only in
     * SwecomReferenceDataSeeder (dev/test setup for the swecom tenant).
     */
    public function run(): void
    {
        $this->seedExpenseCategories();
        $this->seedCompany();

        // RBAC v2 system roles (super/admin/manager/agent/worker) with their
        // day-1 permission sets. Idempotent — see DefaultRolesSeeder. Also
        // seeded by the 2026_09_02_000100 migration for already-provisioned
        // tenants; running both is harmless.
        $this->call(DefaultRolesSeeder::class);
    }

    /**
     * The "Main Branch" row is seeded by the branches table's own creation
     * migration (2026_08_24_160000_create_branches_table.php), which always
     * runs before seeding in the tenant provisioning pipeline.
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

    /**
     * The single company row, named after the workspace and otherwise blank:
     * the new company fills in its own details (registration form, then
     * Settings → Company). `location` and `phone` are NOT NULL columns, so
     * they start as empty strings.
     */
    private function seedCompany(): void
    {
        $now = now();

        DB::table('companies')->insert([
            'branch_id' => $this->mainBranchId(),
            'name' => mb_substr((string) (tenant()?->name ?: 'My Company'), 0, 50),
            'location' => '',
            'email' => null,
            'phone' => '',
            'tech_number' => null,
            'momo_number' => null,
            'momo_name' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
