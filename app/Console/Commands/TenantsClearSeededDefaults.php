<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\LandlordActivity;
use App\Models\Tenant;
use Database\Seeders\SwecomReferenceDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cleans workspaces created before TenantDatabaseSeeder stopped copying
 * SWECOM's reference data into every new tenant: removes SWECOM's 29 Kumba
 * zones where no customer uses them, and blanks any company field still
 * holding SWECOM's details (name → the workspace's own name). Never touches
 * the swecom tenant itself. Dry run unless --force.
 *
 *   php artisan tenants:clear-seeded-defaults acme
 *   php artisan tenants:clear-seeded-defaults --all --force
 */
class TenantsClearSeededDefaults extends Command
{
    protected $signature = 'tenants:clear-seeded-defaults
        {tenant? : Workspace id (omit with --all)}
        {--all : Every workspace except swecom}
        {--force : Actually change the data (default: show what would change)}';

    protected $description = "Remove SWECOM's zones and company details that older provisioning copied into other workspaces";

    private const string PROTECTED_TENANT = 'swecom';

    public function handle(): int
    {
        $ids = $this->option('all')
            ? Tenant::query()->where('id', '!=', self::PROTECTED_TENANT)->pluck('id')->all()
            : array_filter([(string) $this->argument('tenant')]);

        if ($ids === []) {
            $this->error('Name a workspace, or pass --all.');

            return self::FAILURE;
        }

        if (in_array(self::PROTECTED_TENANT, $ids, true)) {
            $this->error('swecom keeps its own data; refusing to touch it.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        foreach ($ids as $id) {
            $tenant = Tenant::find($id);

            if (! $tenant) {
                $this->warn("[{$id}] not found — skipped.");

                continue;
            }

            tenancy()->initialize($tenant);

            try {
                $this->cleanTenant($tenant, $force);
            } finally {
                tenancy()->end();
            }
        }

        if (! $force) {
            $this->line('Dry run — re-run with --force to apply.');
        }

        return self::SUCCESS;
    }

    private function cleanTenant(Tenant $tenant, bool $force): void
    {
        $seededZones = DB::table('zones')
            ->whereIn('name', SwecomReferenceDataSeeder::ZONES)
            ->where('town', 'KUMBA 3')
            ->get(['id', 'name']);

        $usedZoneIds = DB::table('customers')->whereIn('zone_id', $seededZones->pluck('id'))->distinct()->pluck('zone_id')->all();
        $removable = $seededZones->reject(fn (object $zone) => in_array($zone->id, $usedZoneIds, true));

        $company = Company::query()->first();
        $changes = [];

        if ($company) {
            foreach (SwecomReferenceDataSeeder::COMPANY as $field => $swecomValue) {
                if ((string) $company->{$field} === $swecomValue) {
                    $changes[$field] = match ($field) {
                        'name' => mb_substr((string) $tenant->name, 0, 50),
                        'location', 'phone' => '',
                        default => null,
                    };
                }
            }
        }

        $kept = $seededZones->count() - $removable->count();
        $this->line(sprintf(
            '[%s] %d SWECOM zone(s) to remove%s; company fields to clear: %s',
            $tenant->id,
            $removable->count(),
            $kept > 0 ? " ({$kept} kept: customers use them)" : '',
            $changes === [] ? 'none' : implode(', ', array_keys($changes)),
        ));

        if (! $force || ($removable->isEmpty() && $changes === [])) {
            return;
        }

        DB::transaction(function () use ($removable, $company, $changes) {
            DB::table('zones')->whereIn('id', $removable->pluck('id'))->delete();

            if ($company && $changes !== []) {
                $company->update($changes);
                Company::forgetCache();
            }
        });

        LandlordActivity::record(
            'tenant.defaults_cleared',
            "Cleared SWECOM's seeded zones/company details from {$tenant->name} (command line)",
            'tenant',
            $tenant->id,
            ['zones_removed' => $removable->count(), 'company_fields' => array_keys($changes)],
        );
    }
}
