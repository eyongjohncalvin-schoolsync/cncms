<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TenantImportService;
use App\Support\TenantArchive;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads a `tenant:export` archive into this server
 * (App\Services\TenantImportService) — all or nothing, verified table by
 * table against the archive's checksums.
 *
 *   php artisan tenant:import /home/cncms/swecom-20261022-2200.cncms
 *
 * Preconditions: same APP_KEY as the source, `php artisan migrate` already
 * run, and no users or tenants created here yet that could clash with the
 * imported ids.
 */
class TenantImport extends Command
{
    protected $signature = 'tenant:import
        {archive : Path to the .cncms archive}
        {--skip-new-migrations : Do not run tenant migrations newer than the source afterwards}';

    protected $description = 'Import a tenant archive written by tenant:export (verified, all-or-nothing)';

    public function handle(TenantImportService $importer): int
    {
        $this->line('This server\'s APP_KEY fingerprint: '.TenantArchive::appKeyFingerprint());

        try {
            $summary = $importer->import(
                (string) $this->argument('archive'),
                fn (string $message) => $this->line($message),
                ! $this->option('skip-new-migrations'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported [{$summary['tenant_id']}] into schema {$summary['schema']}: {$summary['tables']} tables, {$summary['rows']} rows, {$summary['files']} files — every table verified.");

        return self::SUCCESS;
    }
}
