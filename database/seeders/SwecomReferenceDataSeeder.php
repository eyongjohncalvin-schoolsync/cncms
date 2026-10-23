<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SWECOM PLC's own reference data — its 29 Kumba 3 zones and its company
 * details — for the dev and test setup of the `swecom` tenant only
 * (DatabaseSeeder runs it inside swecom's tenancy). Never part of the
 * generic TenantDatabaseSeeder, so other workspaces start clean.
 *
 * Production's swecom came across with its real data via tenant:import and
 * never runs this. Idempotent: existing zones are kept, and the company row
 * is only filled while it still has the generic blank defaults.
 */
class SwecomReferenceDataSeeder extends Seeder
{
    /** @var list<string> */
    public const array ZONES = [
        'THR01 (3/CORNERS)', 'TR 03', 'AR01(JUNCTION)', 'AR02(HANS)', 'AR03(SEC)',
        'AR04', 'AR 05 (BELMONT)', 'GARE 1', 'LR R01 (LADUMA ROAD)', 'LR R02',
        'M R01 (DODO)', 'M R02', 'M R03 (OIL MILL)', 'M R04 (JERUME)', 'MA R01',
        'MA R02(ELDER)', 'T R01', 'T R02 (BAPTIST)', 'T R03 (BENJI)', 'T R04 (weldering)',
        'T R05(BELTHA)', 'T R06(UNCLE ETA)', 'T R07(school)', 'TRANSMITTER', 'MAHOLE 6',
        'NDIFANG', 'BI', 'TANCHA SCHOOL', 'BANGA FARM',
    ];

    /**
     * The details the old generic seeder stamped on every workspace — also
     * what tenants:clear-seeded-defaults looks for.
     *
     * @var array{name: string, location: string, email: string, phone: string, momo_number: string, momo_name: string}
     */
    public const array COMPANY = [
        'name' => 'SWECOM PLC',
        'location' => '3/CORNERS',
        'email' => 'shalomtech@gmail.com',
        'phone' => '676876509/672528022',
        'momo_number' => '676876509/672528022',
        'momo_name' => 'MUNGWAN HANS/KELVIN MEKUME',
    ];

    public function run(): void
    {
        $now = now();
        $branchId = (int) DB::table('branches')->where('name', 'Main Branch')->value('id');
        $existing = DB::table('zones')->pluck('name')->all();

        $missing = array_values(array_diff(self::ZONES, $existing));

        if ($missing !== []) {
            DB::table('zones')->insert(array_map(static fn (string $name): array => [
                'branch_id' => $branchId,
                'name' => $name,
                'town' => 'KUMBA 3',
                'created_at' => $now,
                'updated_at' => $now,
            ], $missing));
        }

        DB::table('companies')
            ->where(fn ($query) => $query->whereNull('momo_number')->where('phone', ''))
            ->update([...self::COMPANY, 'updated_at' => $now]);
    }
}
