<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompensationBatch;
use App\Models\User;
use App\Services\CompensationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Gives every customer of an approved zone/branch compensation their free
 * months (App\Services\CompensationService::apply()), off the request — a
 * branch can be thousands of customers, each recalculated. Safe to run
 * again: requests already given are skipped. Stancl's
 * QueueTenancyBootstrapper puts the job back in its tenant's schema.
 */
class ApplyCompensationBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $batchId,
        public readonly int $approvedByUserId,
    ) {}

    public function handle(CompensationService $compensation): void
    {
        $batch = CompensationBatch::query()->find($this->batchId);
        $approver = User::query()->find($this->approvedByUserId);

        if ($batch === null || $approver === null || $batch->status !== CompensationBatch::STATUS_APPROVED) {
            return;
        }

        $compensation->apply($batch, $approver);
    }
}
