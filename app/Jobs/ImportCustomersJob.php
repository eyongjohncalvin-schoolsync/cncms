<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Upload;
use App\Services\CustomerImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Imports a customer spreadsheet too big to import while the user waits
 * (CustomerImportService::SYNC_ROW_LIMIT). The Customers page shows the
 * upload's progress and then its report. Stancl's QueueTenancyBootstrapper
 * puts the job back in its tenant's schema; $branchId carries the importer's
 * branch fence, which a queued job has no signed-in user to give.
 */
class ImportCustomersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly int $uploadId,
        public readonly ?int $branchId,
    ) {}

    public function handle(CustomerImportService $imports): void
    {
        $upload = Upload::query()->find($this->uploadId);

        if ($upload !== null) {
            $imports->importQueued($upload, $this->branchId);
        }
    }

    /** A crash shows on the Customers page as a failed import, never "importing" forever. */
    public function failed(Throwable $e): void
    {
        Upload::query()->whereKey($this->uploadId)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'errors' => json_encode([1 => 'The import stopped: '.$e->getMessage()])]);
    }
}
