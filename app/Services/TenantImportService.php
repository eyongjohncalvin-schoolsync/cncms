<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Support\TenantArchive;
use Closure;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Loads an archive written by App\Services\TenantExportService into this
 * server — the swecom dev → production cutover. All or nothing:
 *
 *   1. Preflight — the archive opens (same APP_KEY), the tenant, its schema,
 *      its users/domains/ids don't already exist here, and this code has
 *      every migration the source had run. Nothing is written if any fails.
 *   2. Central rows (tenant, domains, users, memberships, tokens), committed.
 *   3. The tenant schema is built with exactly the source's migrations, so
 *      its columns match the archive one-for-one; then emptied of anything
 *      those migrations seeded, and every row loaded in one transaction with
 *      foreign keys deferred. Every table's row count and checksum must match
 *      the manifest before that transaction commits.
 *   4. Storage files are restored.
 *   5. Migrations the source had not run yet (newer code) run last, on the
 *      real data — exactly what a normal deploy would do.
 *
 * Any failure in steps 2–4 removes what was written (central rows, schema,
 * files), leaving this server as it was. Ids are kept as-is (receipt
 * numbers, references and API tokens stay valid), which is why it must run
 * before anyone creates users or tenants on the target.
 */
class TenantImportService
{
    private const array CENTRAL_TABLES = ['users', 'tenants', 'domains', 'tenant_user_index', 'personal_access_tokens'];

    /**
     * @param  Closure(string): void  $log
     * @return array{tenant_id: string, schema: string, tables: int, rows: int, files: int}
     */
    public function import(string $archivePath, Closure $log, bool $runNewMigrations = true): array
    {
        $zip = $this->open($archivePath);

        try {
            $manifest = $this->manifest($zip);
            $tenantId = $manifest['tenant_id'];
            $db = DB::connection('pgsql');

            $this->preflight($db, $zip, $manifest);
            $log("Preflight passed for tenant [{$tenantId}] (exported {$manifest['exported_at']}).");

            $db->transaction(fn () => $this->loadCentral($db, $zip, $manifest));
            $log('Central rows loaded and verified.');

            $tenant = Tenant::findOrFail($tenantId);
            $schema = $tenant->database()->getName();
            $filesRoot = storage_path(config('tenancy.filesystem.suffix_base').$tenantId.'/app');
            $filesRootExisted = is_dir($filesRoot);

            try {
                $tenant->database()->manager()->createDatabase($tenant);
                $this->migrateTenant($tenantId, $manifest['tenant_migrations']);
                $log("Schema [{$schema}] built with the source's ".count($manifest['tenant_migrations']).' migrations.');

                $rows = $this->loadTenant($db, $zip, $manifest, $schema);
                $log("Tenant data loaded and verified: {$rows} rows.");

                $files = $this->extractFiles($zip, $filesRoot);
                $log("{$files} storage file(s) restored.");
            } catch (Throwable $e) {
                $log('Import failed — removing everything it wrote.');
                $this->undo($db, $zip, $tenant, $filesRootExisted ? null : $filesRoot);

                throw $e;
            }

            if ($runNewMigrations) {
                Artisan::call('tenants:migrate', ['--tenants' => [$tenantId], '--force' => true]);
                $log('Newer migrations: '.trim(Artisan::output()));
            }

            $tenantTables = array_filter($manifest['tables'], fn (string $key) => str_starts_with($key, TenantArchive::TENANT_SCHEMA_KEY.'.'), ARRAY_FILTER_USE_KEY);

            return [
                'tenant_id' => $tenantId,
                'schema' => $schema,
                'tables' => count($tenantTables),
                'rows' => $rows,
                'files' => $files,
            ];
        } finally {
            $zip->close();
        }
    }

    private function open(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive;

        if (! is_file($archivePath) || $zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Could not open [{$archivePath}].");
        }

        $zip->setPassword(TenantArchive::password());

        return $zip;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(ZipArchive $zip): array
    {
        $json = $zip->getFromName('manifest.json');

        if ($json === false) {
            throw new RuntimeException('Could not decrypt the archive: this server\'s APP_KEY (fingerprint '.TenantArchive::appKeyFingerprint().') is not the one it was exported with. Production must use the source APP_KEY — receipt security codes depend on it.');
        }

        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (($manifest['format'] ?? null) !== TenantArchive::FORMAT) {
            throw new RuntimeException('Unsupported archive format ['.($manifest['format'] ?? '?').'].');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function preflight(Connection $db, ZipArchive $zip, array $manifest): void
    {
        $problems = [];
        $tenantId = $manifest['tenant_id'];

        if (Tenant::find($tenantId)) {
            $problems[] = "Tenant [{$tenantId}] already exists here.";
        }

        if (TenantArchive::schemaExists($db, $manifest['source_schema'])) {
            $problems[] = "Schema [{$manifest['source_schema']}] already exists here.";
        }

        $missingCentral = array_diff($manifest['central_migrations'], $db->table('migrations')->pluck('migration')->all());

        if ($missingCentral !== []) {
            $problems[] = 'Central migrations the source had but this server has not run (run `php artisan migrate` or deploy newer code): '.implode(', ', $missingCentral);
        }

        $missingFiles = array_filter($manifest['tenant_migrations'], fn (string $name) => ! is_file(database_path("migrations/tenant/{$name}.php")));

        if ($missingFiles !== []) {
            $problems[] = 'This code is older than the source — tenant migrations missing: '.implode(', ', $missingFiles);
        }

        foreach (self::CENTRAL_TABLES as $table) {
            $missingColumns = array_diff($manifest['tables']["public.{$table}"]['columns'], TenantArchive::columns($db, 'public', $table));

            if ($missingColumns !== []) {
                $problems[] = "Central table [{$table}] lacks columns: ".implode(', ', $missingColumns);

                continue;
            }

            $rows = iterator_to_array($this->rows($zip, "public.{$table}"));

            $taken = $db->table($table)->whereIn('id', array_column($rows, 'id'))->pluck('id')->all();

            if ($taken !== []) {
                $problems[] = "[{$table}] ids already used here: ".implode(', ', array_slice($taken, 0, 10));
            }

            foreach (['users' => ['email', 'username', 'uuid'], 'domains' => ['domain']][$table] ?? [] as $unique) {
                $clashes = $db->table($table)->whereIn($unique, array_filter(array_column($rows, $unique)))->pluck($unique)->all();

                if ($clashes !== []) {
                    $problems[] = "[{$table}.{$unique}] already used here: ".implode(', ', array_slice($clashes, 0, 10));
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException("Nothing was imported:\n - ".implode("\n - ", $problems));
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function loadCentral(Connection $db, ZipArchive $zip, array $manifest): void
    {
        foreach (self::CENTRAL_TABLES as $table) {
            $this->insertRows($db, $zip, "public.{$table}", "public.{$table}", $manifest['tables']["public.{$table}"]['columns']);
        }

        foreach (self::CENTRAL_TABLES as $table) {
            foreach (TenantArchive::sequencedColumns($db, 'public') as $sequenced) {
                if ($sequenced['table'] === $table) {
                    TenantArchive::resetSequence($db, 'public', $table, $sequenced['column']);
                }
            }

            $ids = array_column(iterator_to_array($this->rows($zip, "public.{$table}")), 'id');
            $this->verify($manifest['tables']["public.{$table}"], $db->table("public.{$table}")->whereIn('id', $ids)->get(), "public.{$table}");
        }
    }

    /**
     * @param  list<string>  $migrations
     */
    private function migrateTenant(string $tenantId, array $migrations): void
    {
        $exitCode = Artisan::call('tenants:migrate', [
            '--tenants' => [$tenantId],
            '--path' => array_map(fn (string $name) => database_path("migrations/tenant/{$name}.php"), $migrations),
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Building the tenant schema failed: '.Artisan::output());
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function loadTenant(Connection $db, ZipArchive $zip, array $manifest, string $schema): int
    {
        $prefix = TenantArchive::TENANT_SCHEMA_KEY.'.';
        $tables = array_filter($manifest['tables'], fn (string $key) => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
        $migratedTables = TenantArchive::tables($db, $schema);

        foreach ($tables as $key => $table) {
            $name = substr($key, strlen($prefix));

            // A table no migration builds (a hand-made backup such as
            // manuscripts_2026_08_snapshot) is recreated from its recorded
            // column types — no indexes or constraints, data only.
            if (! in_array($name, $migratedTables, true)) {
                $this->createFromDefinition($db, $schema, $name, $table['definition']);

                continue;
            }

            $targetColumns = TenantArchive::columns($db, $schema, $name);

            if (array_diff($table['columns'], $targetColumns) !== [] || array_diff($targetColumns, $table['columns']) !== []) {
                throw new RuntimeException("Table [{$name}] does not match the archive after migrating — source and target code differ.");
            }
        }

        $targetTables = array_values(array_diff(TenantArchive::tables($db, $schema), ['migrations']));

        $relaxed = $this->deferForeignKeys($db, $schema);

        try {
            return $db->transaction(function () use ($db, $zip, $tables, $targetTables, $schema, $prefix) {
                $db->statement('SET CONSTRAINTS ALL DEFERRED');

                if ($targetTables !== []) {
                    $db->statement('TRUNCATE '.implode(', ', array_map(fn (string $table) => TenantArchive::qualified($schema, $table), $targetTables)).' RESTART IDENTITY CASCADE');
                }

                $rows = 0;

                foreach ($tables as $key => $table) {
                    $rows += $this->insertRows($db, $zip, $key, $schema.'.'.substr($key, strlen($prefix)), $table['columns']);
                }

                foreach (TenantArchive::sequencedColumns($db, $schema) as $sequenced) {
                    TenantArchive::resetSequence($db, $schema, $sequenced['table'], $sequenced['column']);
                }

                foreach ($tables as $key => $table) {
                    $this->verify($table, TenantArchive::stream($db, 'SELECT * FROM '.TenantArchive::qualified($schema, substr($key, strlen($prefix)))), $key);
                }

                $db->statement('SET CONSTRAINTS ALL IMMEDIATE');

                return $rows;
            });
        } finally {
            foreach ($relaxed as $constraint) {
                $db->statement("ALTER TABLE {$constraint->table_name} ALTER CONSTRAINT \"{$constraint->conname}\" NOT DEFERRABLE");
            }
        }
    }

    /**
     * @param  list<array{name: string, type: string, not_null: bool}>  $definition
     */
    private function createFromDefinition(Connection $db, string $schema, string $table, array $definition): void
    {
        if ($definition === []) {
            throw new RuntimeException("Table [{$table}] is not built by any migration and the archive has no definition for it.");
        }

        $columns = array_map(
            fn (array $column) => '"'.str_replace('"', '""', $column['name']).'" '.$column['type'].($column['not_null'] ? ' NOT NULL' : ''),
            $definition,
        );

        $db->statement('CREATE TABLE '.TenantArchive::qualified($schema, $table).' ('.implode(', ', $columns).')');
    }

    /**
     * Makes the schema's foreign keys deferrable for the load (rows go in
     * table by table, not in dependency order); returns the ones to put back.
     *
     * @return list<object{table_name: string, conname: string}>
     */
    private function deferForeignKeys(Connection $db, string $schema): array
    {
        $constraints = $db->select(
            "SELECT conrelid::regclass::text AS table_name, conname FROM pg_constraint WHERE contype = 'f' AND NOT condeferrable AND connamespace = ?::regnamespace",
            [$schema],
        );

        foreach ($constraints as $constraint) {
            $db->statement("ALTER TABLE {$constraint->table_name} ALTER CONSTRAINT \"{$constraint->conname}\" DEFERRABLE INITIALLY IMMEDIATE");
        }

        return $constraints;
    }

    /**
     * @param  list<string>  $columns
     */
    private function insertRows(Connection $db, ZipArchive $zip, string $key, string $table, array $columns): int
    {
        $chunkSize = max(1, intdiv(30000, max(1, count($columns))));
        $chunk = [];
        $count = 0;

        foreach ($this->rows($zip, $key) as $row) {
            $chunk[] = array_intersect_key($row, array_flip($columns));
            $count++;

            if (count($chunk) >= $chunkSize) {
                $db->table($table)->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $db->table($table)->insert($chunk);
        }

        return $count;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function rows(ZipArchive $zip, string $key): Generator
    {
        $stream = $zip->getStream("data/{$key}.ndjson");

        if ($stream === false) {
            throw new RuntimeException("The archive is missing data/{$key}.ndjson.");
        }

        try {
            while (($line = fgets($stream)) !== false) {
                if (trim($line) !== '') {
                    yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array{columns: list<string>, rows: int, checksum: string}  $expected
     * @param  iterable<object>  $actualRows
     */
    private function verify(array $expected, iterable $actualRows, string $key): void
    {
        // Only the 16-byte row hashes are held, never the rows themselves.
        $hashes = [];

        foreach ($actualRows as $row) {
            $hashes[] = TenantArchive::rowHash((array) $row, $expected['columns']);
        }

        if (count($hashes) !== $expected['rows']) {
            throw new RuntimeException("Verification failed for [{$key}]: expected {$expected['rows']} rows, found ".count($hashes).'.');
        }

        if (TenantArchive::combine($hashes) !== $expected['checksum']) {
            throw new RuntimeException("Verification failed for [{$key}]: the loaded rows differ from the archive.");
        }
    }

    private function extractFiles(ZipArchive $zip, string $root): int
    {
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (! str_starts_with($name, 'files/') || str_ends_with($name, '/')) {
                continue;
            }

            $relative = substr($name, strlen('files/'));

            if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
                throw new RuntimeException("Refusing unsafe archive path [{$name}].");
            }

            $target = $root.'/'.$relative;
            File::ensureDirectoryExists(dirname($target));

            $in = $zip->getStream($name);
            $out = fopen($target, 'wb');

            if ($in === false || $out === false) {
                throw new RuntimeException("Could not restore [{$name}].");
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $count++;
        }

        return $count;
    }

    /**
     * Removes what a failed import wrote. Preflight guaranteed none of these
     * ids existed before, so deleting by the archive's ids is exact.
     */
    private function undo(Connection $db, ZipArchive $zip, Tenant $tenant, ?string $createdFilesRoot): void
    {
        if (TenantArchive::schemaExists($db, $tenant->database()->getName())) {
            $tenant->database()->manager()->deleteDatabase($tenant);
        }

        $db->transaction(function () use ($db, $zip) {
            foreach (array_reverse(self::CENTRAL_TABLES) as $table) {
                $ids = array_column(iterator_to_array($this->rows($zip, "public.{$table}")), 'id');
                $db->table($table)->whereIn('id', $ids)->delete();
            }
        });

        if ($createdFilesRoot !== null) {
            File::deleteDirectory($createdFilesRoot);
        }
    }
}
