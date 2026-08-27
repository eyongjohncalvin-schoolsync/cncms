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

        // is_landlord is deliberately excluded from User's #[Fillable] list
        // (see the model's docblock) — grant it via direct property
        // assignment, not mass-assignment, self-granted here only because
        // this seeder represents the platform's actual real-world owner.
        if (! $owner->is_landlord) {
            $owner->is_landlord = true;
            $owner->landlord_granted_at = now();
            $owner->save();
        }

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
