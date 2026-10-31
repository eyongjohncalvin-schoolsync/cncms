<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

/**
 * The server's own database dumps (deploy/scripts/backup-db.sh), for the
 * landlord Backups page: list them, make one now, and hand one out as a
 * password-locked zip.
 *
 * Each dump is the WHOLE database — every tenant and every password hash —
 * so only file names matching the script's own pattern are ever served,
 * never an arbitrary path.
 */
class DatabaseBackupService
{
    public const string FILE_PATTERN = '/^cncms-\d{4}-\d{2}-\d{2}-\d{4}\.sql\.gz$/';

    /** Older than this and the nightly backup has stopped running. */
    public const int STALE_HOURS = 25;

    private const string RUNNING_KEY = 'database-backup:running';

    public function path(): string
    {
        return rtrim((string) config('filesystems.backups_path'), '/\\');
    }

    /**
     * Newest first.
     *
     * @return list<array{name: string, bytes: int, created_at: string}>
     */
    public function list(): array
    {
        $files = [];

        foreach (glob($this->path().'/cncms-*.sql.gz') ?: [] as $file) {
            $name = basename($file);

            if (preg_match(self::FILE_PATTERN, $name) === 1 && is_file($file)) {
                $files[] = [
                    'name' => $name,
                    'bytes' => (int) filesize($file),
                    'created_at' => CarbonImmutable::createFromTimestamp((int) filemtime($file))->toIso8601String(),
                ];
            }
        }

        usort($files, fn (array $a, array $b) => strcmp($b['name'], $a['name']));

        return $files;
    }

    public function isStale(): bool
    {
        $newest = $this->list()[0] ?? null;

        return $newest === null || CarbonImmutable::parse($newest['created_at'])->lt(now()->subHours(self::STALE_HOURS));
    }

    /**
     * The full path of a listed dump, or null for any other name.
     */
    public function find(string $name): ?string
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1) {
            return null;
        }

        $file = $this->path().DIRECTORY_SEPARATOR.$name;

        return is_file($file) ? $file : null;
    }

    /**
     * Wraps a dump in an AES-256 zip (opens with 7-Zip or WinRAR) in a
     * temporary file the caller deletes after sending.
     */
    public function lockedZip(string $file, string $password): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'cncms-backup-');
        $entry = basename($file);
        $zip = new ZipArchive;

        if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the zip file.');
        }

        $zip->addFile($file, $entry);
        // Already gzipped: compressing again only costs time.
        $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        // Never hand out an unlocked copy: a PHP without AES support fails here.
        if (! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password)) {
            $zip->close();
            @unlink($zipPath);

            throw new RuntimeException('This server cannot lock zip files (PHP zip without AES).');
        }

        if (! $zip->close()) {
            throw new RuntimeException('Could not write the zip file.');
        }

        return $zipPath;
    }

    public function isRunning(): bool
    {
        return Cache::has(self::RUNNING_KEY);
    }

    /**
     * Claims the single "a backup is being made" slot; false when one is
     * already running. Expires on its own if the job dies.
     */
    public function claim(): bool
    {
        return Cache::add(self::RUNNING_KEY, true, now()->addMinutes(30));
    }

    public function release(): void
    {
        Cache::forget(self::RUNNING_KEY);
    }

    /**
     * Runs backup-db.sh — the same script as the nightly cron and every
     * deploy, so a "Back up now" file is no different from a nightly one.
     */
    public function run(): void
    {
        $result = Process::timeout(1500)
            ->env(['PHP_BIN' => PHP_BINARY, 'BACKUP_DIR' => $this->path()])
            ->run(['bash', base_path('deploy/scripts/backup-db.sh')]);

        if ($result->failed()) {
            throw new RuntimeException('The backup failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }
}
