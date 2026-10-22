<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantArchive;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Writes one tenant's complete data to an encrypted archive (format:
 * App\Support\TenantArchive) for App\Services\TenantImportService to load
 * on another server — the swecom dev → production cutover.
 *
 * Read-only against the source: everything is read inside one REPEATABLE
 * READ READ ONLY transaction, so the archive is a single consistent
 * snapshot even if someone is still working, and nothing is written.
 *
 * What travels: the whole tenant schema (minus its `migrations` table — the
 * list goes in the manifest instead), plus the tenant's rows in the central
 * tables (tenants, domains, tenant_user_index, its users — members and
 * anyone the tenant data points at — and their API tokens), plus its
 * storage files. Sessions, cache and queue tables stay behind.
 */
class TenantExportService
{
    /**
     * @return array{tables: int, rows: int, files: int, bytes: int, schema: string}
     */
    public function export(string $tenantId, string $archivePath, bool $withFiles = true): array
    {
        $tenant = Tenant::find($tenantId) ?? throw new RuntimeException("Tenant [{$tenantId}] does not exist.");
        $schema = $tenant->database()->getName();
        $db = DB::connection('pgsql');

        if (! TenantArchive::schemaExists($db, $schema)) {
            throw new RuntimeException("Tenant schema [{$schema}] does not exist.");
        }

        $workDir = storage_path('framework/tenant-export-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($workDir.'/data');

        try {
            $db->beginTransaction();
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            try {
                $manifest = $this->writeData($db, $tenantId, $schema, $workDir);
            } finally {
                $db->rollBack();
            }

            $files = $withFiles ? $this->tenantFiles($tenantId) : [];
            $manifest['files'] = count($files);

            File::put($workDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $this->zip($workDir, $files, $archivePath);
        } finally {
            File::deleteDirectory($workDir);
        }

        return [
            'tables' => count($manifest['tables']),
            'rows' => array_sum(array_column($manifest['tables'], 'rows')),
            'files' => count($files),
            'bytes' => (int) filesize($archivePath),
            'schema' => $schema,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function writeData(Connection $db, string $tenantId, string $schema, string $workDir): array
    {
        $userIds = $this->userIds($db, $tenantId, $schema);

        $central = [
            'tenants' => fn () => $db->table('public.tenants')->where('id', $tenantId),
            'domains' => fn () => $db->table('public.domains')->where('tenant_id', $tenantId),
            'users' => fn () => $db->table('public.users')->whereIn('id', $userIds),
            'tenant_user_index' => fn () => $db->table('public.tenant_user_index')->where('tenant_id', $tenantId),
            'personal_access_tokens' => fn () => $db->table('public.personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds),
        ];

        $tables = [];

        foreach ($central as $table => $query) {
            $tables["public.{$table}"] = $this->writeTable($query()->orderBy('id')->get(), TenantArchive::columns($db, 'public', $table), $workDir, "public.{$table}");
        }

        foreach (TenantArchive::tables($db, $schema) as $table) {
            if ($table === 'migrations') {
                continue;
            }

            $key = TenantArchive::TENANT_SCHEMA_KEY.".{$table}";
            $rows = TenantArchive::stream($db, 'SELECT * FROM '.TenantArchive::qualified($schema, $table));
            $tables[$key] = [
                ...$this->writeTable($rows, TenantArchive::columns($db, $schema, $table), $workDir, $key),
                'definition' => TenantArchive::definition($db, $schema, $table),
            ];
        }

        return [
            'format' => TenantArchive::FORMAT,
            'tenant_id' => $tenantId,
            'source_schema' => $schema,
            'exported_at' => now()->toIso8601String(),
            'app_key_fingerprint' => TenantArchive::appKeyFingerprint(),
            'central_migrations' => $db->table('public.migrations')->orderBy('id')->pluck('migration')->all(),
            'tenant_migrations' => $db->table("{$schema}.migrations")->orderBy('id')->pluck('migration')->all(),
            'tables' => $tables,
        ];
    }

    /**
     * The tenant's members plus every user its data references through a
     * foreign key into public.users (e.g. a former member who verified
     * payments) — the import would fail those foreign keys otherwise.
     *
     * @return list<int>
     */
    private function userIds(Connection $db, string $tenantId, string $schema): array
    {
        $ids = $db->table('public.tenant_user_index')->where('tenant_id', $tenantId)->pluck('user_id')->all();

        $references = $db->select(<<<'SQL'
            SELECT cl.relname AS table_name, a.attname AS column_name
            FROM pg_constraint c
            JOIN pg_class cl ON cl.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = cl.relnamespace
            JOIN pg_class rcl ON rcl.oid = c.confrelid
            JOIN pg_namespace rn ON rn.oid = rcl.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND n.nspname = ? AND rn.nspname = 'public' AND rcl.relname = 'users'
            SQL, [$schema]);

        foreach ($references as $reference) {
            $column = '"'.str_replace('"', '""', $reference->column_name).'"';
            $rows = $db->select("SELECT DISTINCT {$column} AS user_id FROM ".TenantArchive::qualified($schema, $reference->table_name)." WHERE {$column} IS NOT NULL");
            $ids = [...$ids, ...array_column($rows, 'user_id')];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * @param  iterable<object>  $rows
     * @param  list<string>  $columns
     * @return array{columns: list<string>, rows: int, checksum: string}
     */
    private function writeTable(iterable $rows, array $columns, string $workDir, string $key): array
    {
        $handle = fopen("{$workDir}/data/{$key}.ndjson", 'wb');
        $hashes = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $hashes[] = TenantArchive::rowHash($row, $columns);
        }

        fclose($handle);

        return ['columns' => $columns, 'rows' => count($hashes), 'checksum' => TenantArchive::combine($hashes)];
    }

    /**
     * Everything under storage/<tenant>/app (logos, receipts, bill PDFs,
     * import files) — framework caches are left behind.
     *
     * @return array<string, string> archive path => absolute path
     */
    private function tenantFiles(string $tenantId): array
    {
        $root = storage_path(config('tenancy.filesystem.suffix_base').$tenantId.'/app');

        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        foreach (File::allFiles($root, true) as $file) {
            /** @var SplFileInfo $file */
            $files['files/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(string $workDir, array $files, string $archivePath): void
    {
        File::ensureDirectoryExists(dirname($archivePath));

        if (file_exists($archivePath)) {
            throw new RuntimeException("[{$archivePath}] already exists; refusing to overwrite it.");
        }

        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("Could not create [{$archivePath}].");
        }

        $entries = ['manifest.json' => $workDir.'/manifest.json'];

        foreach (File::files($workDir.'/data') as $file) {
            $entries['data/'.$file->getFilename()] = $file->getPathname();
        }

        try {
            $zip->setPassword(TenantArchive::password());

            foreach ([...$entries, ...$files] as $name => $path) {
                $zip->addFile($path, $name);
                $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
            }

            if (! $zip->close()) {
                throw new RuntimeException("Could not write [{$archivePath}].");
            }
        } catch (Throwable $e) {
            @unlink($archivePath);

            throw $e;
        }
    }
}
