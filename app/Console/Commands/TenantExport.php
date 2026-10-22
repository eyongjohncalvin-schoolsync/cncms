<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TenantExportService;
use App\Support\MaintenanceMode;
use App\Support\TenantArchive;
use Illuminate\Console\Command;
use Throwable;

/**
 * Exports one tenant's complete data to an encrypted archive for
 * `tenant:import` on another server (App\Services\TenantExportService).
 * Read-only against this database.
 *
 *   php artisan tenant:export swecom
 *   php artisan tenant:export swecom --output=D:/cutover/swecom.cncms
 *
 * Run it during a maintenance window (landlord → Maintenance) so nobody
 * records anything after the snapshot is taken; without one it asks first.
 */
class TenantExport extends Command
{
    protected $signature = 'tenant:export
        {tenant : The tenant id, e.g. swecom}
        {--output= : Archive path (default: storage/app/private/exports/<tenant>-<timestamp>.cncms)}
        {--without-files : Leave the tenant\'s storage files (receipts, bill PDFs, logos) out}
        {--force : Export without asking when no maintenance window is running (rehearsals)}';

    protected $description = 'Export a tenant (its schema, central rows and files) to an encrypted archive for tenant:import';

    public function handle(TenantExportService $exporter): int
    {
        $tenantId = (string) $this->argument('tenant');

        if (! MaintenanceMode::active() && ! $this->option('force') && ! $this->confirm('No maintenance window is running, so staff could record work after this snapshot that will not be in it. Export anyway?')) {
            return self::FAILURE;
        }

        $path = (string) ($this->option('output') ?: storage_path("app/private/exports/{$tenantId}-".now()->format('Ymd-His').'.cncms'));

        try {
            $summary = $exporter->export($tenantId, $path, ! $this->option('without-files'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Exported [{$tenantId}] (schema {$summary['schema']}): {$summary['tables']} tables, {$summary['rows']} rows, {$summary['files']} files.");
        $this->line("Archive: {$path} (".number_format($summary['bytes'] / 1048576, 1).' MB)');
        $this->line('APP_KEY fingerprint: '.TenantArchive::appKeyFingerprint().' — the target must show the same one, or the archive will not open.');
        $this->warn('The archive holds password hashes and customer data. Copy it straight to the server and delete it after the import.');

        return self::SUCCESS;
    }
}
