<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LandlordActivity;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The landlord Backups page's "Back up now" (App\Http\Controllers\Landlord\
 * BackupController::store()). pg_dump of the whole database takes too long
 * for the request; the new file simply appears in the list when done.
 */
class RunDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1500;

    /** A failed dump is reported, not retried behind the landlord's back. */
    public int $tries = 1;

    public function __construct(public readonly int $requestedBy) {}

    public function handle(DatabaseBackupService $backups): void
    {
        try {
            $backups->run();
            LandlordActivity::record('backup.created', 'Made a database backup', 'backup', null, null, User::find($this->requestedBy));
        } catch (Throwable $e) {
            LandlordActivity::record('backup.failed', 'A database backup failed', 'backup', null, ['error' => mb_substr($e->getMessage(), 0, 500)], User::find($this->requestedBy));

            throw $e;
        } finally {
            $backups->release();
        }
    }
}
