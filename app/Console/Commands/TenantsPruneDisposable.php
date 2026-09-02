<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Drops disposable test tenants (schema-per-tenant Postgres schemas + the
 * `tenants`/`domains` rows) left behind by killed test runs — factory tenants
 * and `UsesDisposableTenant` cases whose cleanup never ran.
 *
 * SAFETY: the set of tenants to KEEP is a hard-coded literal allowlist. This
 * command never derives "which to keep" from a name pattern — it only decides
 * "which to drop" (everything not in the allowlist). Dry-run by default;
 * requires --force to actually drop anything.
 */
class TenantsPruneDisposable extends Command
{
    protected $signature = 'tenants:prune-disposable
        {--force : Actually drop the schemas and rows. Without this the command only prints the plan.}';

    protected $description = 'Drop orphaned disposable test tenants (schemas + tenants/domains rows), keeping only the real tenants';

    /**
     * The ONLY tenants that must survive. Hard-coded literal — never pattern-derived.
     * If either of these ever lands in the delete list, the filter is broken and
     * the command aborts.
     */
    private const KEEP = [
        'swecom',
        'multimedia-digital-cable-network',
    ];

    public function handle(): int
    {
        $centralConnection = config('tenancy.database.central_connection');
        $schemaPrefix = config('tenancy.database.prefix').''; // 'tenant'
        $schemaSuffix = config('tenancy.database.suffix').'';

        $allIds = Tenant::query()->orderBy('id')->pluck('id')->all();

        $keep = array_values(array_intersect($allIds, self::KEEP));
        $drop = array_values(array_diff($allIds, self::KEEP));

        // Guardrail: an allowlisted tenant must never appear in the drop list.
        $leaked = array_intersect($drop, self::KEEP);
        if ($leaked !== []) {
            $this->error('ABORT: allowlisted tenant(s) in delete list: '.implode(', ', $leaked));

            return self::FAILURE;
        }

        $this->info('Central connection: '.$centralConnection);
        $this->newLine();

        $this->line('<comment>KEEP ('.count($keep).'):</comment>');
        foreach ($keep as $id) {
            $this->line('  - '.$id);
        }
        $this->newLine();

        $this->line('<comment>DROP ('.count($drop).'):</comment>');
        foreach ($drop as $id) {
            $this->line('  - '.$id.'  (schema: '.$schemaPrefix.$id.$schemaSuffix.')');
        }
        $this->newLine();

        // Sanity check for missing allowlisted tenants.
        foreach (self::KEEP as $expected) {
            if (! in_array($expected, $allIds, true)) {
                $this->warn('NOTE: allowlisted tenant "'.$expected.'" not present in tenants table.');
            }
        }

        if (! $this->option('force')) {
            $this->warn('Dry run. Re-run with --force to drop the '.count($drop).' tenant(s) listed above.');

            return self::SUCCESS;
        }

        if ($drop === []) {
            $this->info('Nothing to drop.');

            return self::SUCCESS;
        }

        $dropped = [];
        $failed = [];

        foreach ($drop as $id) {
            try {
                $tenant = Tenant::find($id);

                if ($tenant === null) {
                    // No row — just make sure the schema is gone.
                    $this->dropSchemaRaw($centralConnection, $schemaPrefix.$id.$schemaSuffix);
                    $dropped[] = $id.' (schema only, no tenant row)';

                    continue;
                }

                $tenant->delete();
                $dropped[] = $id;
                $this->line('  dropped '.$id);
            } catch (Throwable $e) {
                // ORM path failed (e.g. corrupted migration ledger). Fall back to
                // raw DROP SCHEMA + manual row cleanup on the central connection.
                $this->warn('  ORM delete failed for '.$id.': '.$e->getMessage());

                try {
                    $schema = $schemaPrefix.$id.$schemaSuffix;
                    $this->dropSchemaRaw($centralConnection, $schema);
                    DB::connection($centralConnection)->table('domains')->where('tenant_id', $id)->delete();
                    DB::connection($centralConnection)->table('tenants')->where('id', $id)->delete();
                    $dropped[] = $id.' (raw fallback)';
                    $this->line('  dropped '.$id.' via raw fallback');
                } catch (Throwable $inner) {
                    $failed[] = $id.': '.$inner->getMessage();
                    $this->error('  raw fallback also failed for '.$id.': '.$inner->getMessage());
                }
            }
        }

        $this->newLine();
        $this->info('Dropped: '.count($dropped));
        foreach ($dropped as $d) {
            $this->line('  - '.$d);
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error('Failed: '.count($failed));
            foreach ($failed as $f) {
                $this->line('  - '.$f);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done. Remaining tenants: '.Tenant::query()->orderBy('id')->pluck('id')->implode(', '));

        return self::SUCCESS;
    }

    private function dropSchemaRaw(string $connection, string $schema): void
    {
        // Identifier can't be bound; it is built from a validated tenant id + config prefix.
        DB::connection($connection)->statement('DROP SCHEMA IF EXISTS "'.str_replace('"', '', $schema).'" CASCADE');
    }
}
