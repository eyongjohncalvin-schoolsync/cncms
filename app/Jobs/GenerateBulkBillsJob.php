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
 * Renders the single bulk PDF for a bill_batches run — every recipient in
 * one PDF, ordered by zone (alphabetical) then customer name (owner's
 * 2026-08-30 async bill generation ask — App\Services\BillBatchService).
 *
 * This is a single dompdf render of the whole zone-ordered recipient list
 * (with memory/time raised in the service), NOT a merge of the per-zone
 * PDFs — no PDF-merge library is present (only barryvdh/laravel-dompdf) and
 * the owner explicitly asked for one real bulk PDF, not a stitched one.
 *
 * Same tenancy story as App\Jobs\GenerateZoneBillsJob — see its class doc.
 */
class GenerateBulkBillsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The whole-tenant render is the heaviest single job in the batch. */
    public int $timeout = 3600;

    /**
     * @param  array<int, int>  $customerIds  Every recipient, in final zone-then-name render order.
     */
    public function __construct(
        public readonly int $billBatchId,
        public readonly string $period,
        public readonly array $customerIds,
    ) {}

    public function handle(BillBatchService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $service->markProcessing($this->billBatchId);
        $service->renderBulkFile($this->billBatchId, $this->period, $this->customerIds);
    }
}
