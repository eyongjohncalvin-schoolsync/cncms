<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Connection;

/**
 * The format shared by App\Services\TenantExportService and
 * TenantImportService: one tenant's complete data (its schema plus its rows
 * in the central tables) in a single AES-256 encrypted zip.
 *
 *   manifest.json            tenant, migrations, per-table columns/rows/checksum
 *   data/<schema>.<table>.ndjson   one JSON row per line ("tenant" stands in for the tenant schema)
 *   files/<path>             the tenant's storage/<tenant>/app tree
 *
 * The zip password is derived from APP_KEY, so an archive only opens on a
 * server with the same key — which production needs anyway, because the
 * receipt security codes (ReceiptSecurityCode) and the `encrypted` casts
 * are keyed by it. The archive holds password hashes and customer data:
 * keep it off shared drives and delete it after the import.
 */
final class TenantArchive
{
    public const int FORMAT = 1;

    public const string TENANT_SCHEMA_KEY = 'tenant';

    public static function password(): string
    {
        return hash_hmac('sha256', 'cncms-tenant-archive', (string) config('app.key'));
    }

    /** Safe to print: identifies the key without revealing it. */
    public static function appKeyFingerprint(): string
    {
        return substr(hash('sha256', 'cncms-key-fingerprint|'.config('app.key')), 0, 16);
    }

    /**
     * @return list<string>
     */
    public static function tables(Connection $db, string $schema): array
    {
        return $db->table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_type', 'BASE TABLE')
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function columns(Connection $db, string $schema, string $table): array
    {
        return $db->table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();
    }

    public static function schemaExists(Connection $db, string $schema): bool
    {
        return $db->table('pg_namespace')->where('nspname', $schema)->exists();
    }

    public static function qualified(string $schema, string $table): string
    {
        return '"'.str_replace('"', '""', $schema).'"."'.str_replace('"', '""', $table).'"';
    }

    /**
     * Order-independent fingerprint of a table's rows. Both sides read rows
     * through pdo_pgsql on a UTC session (config/database.php), so the same
     * data yields the same PHP values on any Postgres version.
     *
     * @param  iterable<array<string, mixed>|object>  $rows
     * @param  list<string>  $columns
     */
    public static function checksum(iterable $rows, array $columns): string
    {
        $hashes = [];

        foreach ($rows as $row) {
            $hashes[] = self::rowHash((array) $row, $columns);
        }

        sort($hashes);

        return hash('sha256', implode("\n", $hashes));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     */
    public static function rowHash(array $row, array $columns): string
    {
        $values = array_map(fn (string $column) => $row[$column] ?? null, $columns);

        return md5(json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Columns fed by a sequence (serial/bigserial ids), whose sequence must
     * be moved past the imported max.
     *
     * @return list<array{table: string, column: string}>
     */
    public static function sequencedColumns(Connection $db, string $schema): array
    {
        return $db->table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('column_default', 'like', 'nextval(%')
            ->orderBy('table_name')
            ->get(['table_name', 'column_name'])
            ->map(fn (object $row) => ['table' => $row->table_name, 'column' => $row->column_name])
            ->all();
    }

    public static function resetSequence(Connection $db, string $schema, string $table, string $column): void
    {
        $qualified = self::qualified($schema, $table);
        $quotedColumn = '"'.str_replace('"', '""', $column).'"';

        $db->statement(
            "SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX({$quotedColumn}) FROM {$qualified}), 1), (SELECT MAX({$quotedColumn}) FROM {$qualified}) IS NOT NULL)",
            [$qualified, $column],
        );
    }
}
