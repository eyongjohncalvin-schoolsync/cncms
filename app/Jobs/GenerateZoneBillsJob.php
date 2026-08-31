<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\BillBatchService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Renders one zone's bill slips into a stored PDF artifact for a
 * bill_batches run (owner's 2026-08-30 async bill generation ask —
 * App\Services\BillBatchService). Zone is the natural chunk unit: it keeps
 * any single job's dompdf memory bounded and maps one-to-one onto the
 * owner's "download bills for one zone at a time" deliverable.
 *
 * Always dispatched from inside an active tenancy()->initialize() context
 * (BillBatchService::dispatch(), called from a tenant web request). Stancl's
 * QueueTenancyBootstrapper (config/tenancy.php) transparently re-initializes
 * the right tenant schema when this job actually runs on the worker — and
 * for the enclosing batch's catch()/finally() closures too — so no tenant
 * id is threaded through this job, exactly like
 * App\Jobs\ComputeManuscriptChunkJob.
 *
 * A hard render failure here is left to propagate: allowFailures() on the
 * batch means it does not cancel the sibling zone jobs or the bulk job, and
 * the finalizer downgrades the run to `partial` rather than `failed` as
 * long as the bulk PDF and at least one zone PDF still landed.
 */
class GenerateZoneBillsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** dompdf over a whole zone can be slow — well past the default 60s. */
    public int $timeout = 1800;

    /**
     * @param  array<int, int>  $customerIds  Internal customer ids for this zone, already name-ordered.
     */
    public function __construct(
        public readonly int $billBatchId,
        public readonly string $period,
        public readonly ?int $zoneId,
        public readonly string $zoneName,
        public readonly array $customerIds,
    ) {}

    public function handle(BillBatchService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $service->markProcessing($this->billBatchId);
        $service->renderZoneFile($this->billBatchId, $this->period, $this->zoneId, $this->zoneName, $this->customerIds);
    }
}
