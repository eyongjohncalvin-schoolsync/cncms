<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the central database: the real SWECOM owner account and the
     * SWECOM tenant itself. Creating the Tenant fires Stancl's TenantCreated
     * event pipeline (CreateDatabase -> MigrateDatabase -> SeedDatabase),
     * which provisions the `tenant_swecom` schema, runs the tenant
     * migrations, and runs TenantDatabaseSeeder (zones, expense categories,
     * company record) — so model events must stay enabled here.
     */
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'kelvin@shalomtech.dev'],
            [
                'name' => 'Ebaieyong Kelvin Mekume',
                'username' => 'miskhan',
                'status' => 'active',
                'password' => 'password',
                'email_verified_at' => now(),
            ]
        );

        $tenant = Tenant::firstOrCreate(['id' => 'swecom'], [
            'name' => 'SWECOM PLC',
            'slug' => 'swecom',
        ]);

        $tenant->domains()->firstOrCreate(['domain' => 'swecom.localhost']);

        tenancy()->initialize($tenant);

        TenantUser::firstOrCreate(
            ['user_id' => $owner->id, 'tenant_id' => $tenant->id],
            ['role' => 'super']
        );

        tenancy()->end();
    }
}
