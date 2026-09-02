<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateBulkBillsJob;
use App\Jobs\GenerateZoneBillsJob;
use App\Models\BillBatch;
use App\Models\BillBatchFile;
use App\Models\Company;
use App\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Asynchronous (queued) bill generation — the owner's 2026-08-30 ask to
 * move the synchronous `GET /manuscripts/bills` (memory_limit 1024M /
 * time 180s inside a web request — will not survive a thousands-customer
 * tenant) into a background Bus::batch() that writes downloadable PDF
 * artifacts. Deliberately models the exact same mechanism as
 * App\Services\ManuscriptGenerationBatchService: chunk the work by the
 * natural unit (here: zone), Bus::batch() with allowFailures(), primitive-
 * only batch-callback closures that resolve `app(self::class)` at execution
 * time, and Stancl's QueueTenancyBootstrapper transparently re-initializing
 * the tenant schema on the worker (so no tenant id is threaded through the
 * jobs — see App\Jobs\GenerateZoneBillsJob's class doc).
 *
 * Two deliverables per run (owner's spec):
 *   1. Per-zone PDFs — one artifact per zone (GenerateZoneBillsJob).
 *   2. One bulk PDF — every recipient in a single PDF, ordered by zone
 *      (alphabetical) then customer name (GenerateBulkBillsJob). A single
 *      dompdf render of the zone-ordered list, NOT a merge of the per-zone
 *      files (no merge library is present — only barryvdh/laravel-dompdf).
 * Plus a convenience ZIP of the per-zone PDFs, built by the finalizer.
 *
 * Recipient list + ordering is entirely
 * App\Services\ManuscriptService::billRecipients() — already active-only and
 * already ordered zone-then-name (case-insensitive).
 */
class BillBatchService
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
    ) {}

    /**
     * Where per-batch PDF/ZIP artifacts are written. Follows the app's
     * configured default disk — `local` (private) on the VPS deploy, an
     * object-storage disk on an ephemeral host like Laravel Cloud (set
     * FILESYSTEM_DISK there, or the generated files vanish on redeploy).
     * The `bill_batch_files.disk` column records what each file actually
     * used, so the download route stays correct if this later changes.
     */
    private function disk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    /**
     * Creates the bill_batches row and dispatches the batch. Returns
     * immediately — with a real (database) queue connection the artifacts
     * almost certainly do not exist yet when this returns.
     *
     * @param  array<string, mixed>  $filters  billRecipients() keys: zone_uuid, status, search (period is forced to $period).
     */
    public function dispatch(string $period, array $filters, ?int $userId): BillBatch
    {
        $filters['period'] = $period;

        ['period' => $resolvedPeriod, 'customers' => $customers] = $this->manuscripts->billRecipients($filters);

        if ($customers->isEmpty()) {
            throw ValidationException::withMessages([
                'period' => ["No active customers have a bill for {$resolvedPeriod} — nothing to generate."],
            ]);
        }

        $company = Company::cached();
        $density = $company?->bills_per_page ?? 1;
        $template = $company?->bill_template ?? 'classic';

        $customers->loadMissing('zone');
        // groupBy preserves the incoming (already zone-then-name) order, so
        // each group's customers are still name-ordered within the zone.
        $byZone = $customers->groupBy(fn (Customer $c): int => $c->zone_id ?? 0);

        $billBatch = BillBatch::create([
            'period' => $resolvedPeriod,
            'status' => 'queued',
            'density' => $density,
            'template' => $template,
            'filters' => array_filter([
                'period' => $resolvedPeriod,
                'zone_uuid' => $filters['zone_uuid'] ?? null,
                'status' => $filters['status'] ?? null,
                'search' => $filters['search'] ?? null,
            ], fn ($v): bool => $v !== null && $v !== ''),
            'total_bills' => $customers->count(),
            'total_zones' => $byZone->count(),
            'generated_by' => $userId,
        ]);

        $jobs = [];

        foreach ($byZone as $zoneKey => $group) {
            $zoneId = ((int) $zoneKey) ?: null;
            $zoneName = $group->first()?->zone?->name ?? 'Unzoned';

            $jobs[] = new GenerateZoneBillsJob(
                $billBatch->id,
                $resolvedPeriod,
                $zoneId,
                $zoneName,
                $group->pluck('id')->all(),
            );
        }

        $jobs[] = new GenerateBulkBillsJob($billBatch->id, $resolvedPeriod, $customers->pluck('id')->all());

        $billBatchId = $billBatch->id;

        $batch = Bus::batch($jobs)
            ->name("bill_generation:{$resolvedPeriod}:{$billBatchId}")
            // Own queue — dompdf over every zone is the slowest work in the
            // app and must never block tenant creation / notifications on
            // the default queue. Worker: `--queue=default,manuscripts,bills`.
            ->onQueue('bills')
            ->allowFailures()
            ->catch(function (Batch $batch, Throwable $e) use ($billBatchId): void {
                app(self::class)->handleBatchFailed($billBatchId, $e);
            })
            ->finally(function (Batch $batch) use ($billBatchId): void {
                app(self::class)->finalize($billBatchId, $batch->failedJobs, $batch->totalJobs);
            })
            ->dispatch();

        $billBatch->update(['batch_id' => $batch->id]);

        return $billBatch->fresh();
    }

    /**
     * Flips a still-`queued` batch to `processing` (first job to run wins);
     * a no-op afterwards (including for a `cancelled` batch — the `queued`
     * guard means a cancelled run is never dragged back to `processing`).
     * Called at the top of every job's handle().
     */
    public function markProcessing(int $billBatchId): void
    {
        BillBatch::query()
            ->where('id', $billBatchId)
            ->where('status', 'queued')
            ->update(['status' => 'processing', 'started_at' => now()]);
    }

    /**
     * Cancel an in-flight run (owner's ask — "there's no cancel, because I
     * made an error"). Cancels the underlying Bus batch so any not-yet-run
     * job no-ops (each job already guards on `batch()?->cancelled()`), flips
     * the row to `cancelled`, and discards whatever partial artifacts landed
     * before the cancel took effect. A no-op on an already-terminal run.
     */
    public function cancel(BillBatch $billBatch): void
    {
        if ($billBatch->isTerminal()) {
            return;
        }

        $this->cancelBusBatch($billBatch->batch_id);

        $billBatch->update(['status' => 'cancelled', 'completed_at' => now()]);

        $this->discardArtifacts($billBatch);
    }

    /**
     * Clear a run entirely — deletes its stored PDF/ZIP artifacts and the
     * bill_batches row (bill_batch_files cascade). Cancels first if it is
     * somehow still in flight. Used for "I want to regenerate": clear the
     * bad run, then start a fresh one.
     */
    public function delete(BillBatch $billBatch): void
    {
        if (! $billBatch->isTerminal()) {
            $this->cancelBusBatch($billBatch->batch_id);
        }

        $this->discardArtifacts($billBatch);

        $billBatch->delete();
    }

    /**
     * Marks the underlying Bus batch cancelled so any not-yet-run job
     * no-ops (each guards on `batch()?->cancelled()`).
     *
     * `Bus::findBatch()` reads `job_batches` on the default connection,
     * which is the tenant-scoped one while tenancy is initialized — and
     * `job_batches` lives ONLY in the central schema (see
     * App\Support\ResolvesCommandRunBatchProgress for the same hazard). So
     * pin to the central connection and set `cancelled_at` directly, exactly
     * as Illuminate\Bus\Batch::cancel() does.
     */
    private function cancelBusBatch(?string $batchId): void
    {
        if ($batchId === null) {
            return;
        }

        DB::connection(config('tenancy.database.central_connection'))
            ->table('job_batches')
            ->where('id', $batchId)
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now()->getTimestamp()]);
    }

    /**
     * Removes every stored artifact for a run (the whole per-batch
     * directory) and its bill_batch_files rows. Idempotent — safe to call
     * from both cancel() and finalize() for a cancelled run regardless of
     * which runs last.
     */
    private function discardArtifacts(BillBatch $billBatch): void
    {
        Storage::disk($this->disk())->deleteDirectory($this->basePath($billBatch));

        $billBatch->files()->delete();
    }

    /**
     * Renders one zone's bill slips and records the artifact. Idempotent on
     * retry (updateOrCreate keyed on batch+kind+zone). An empty render (no
     * customer in the zone has a manuscript for the period) writes nothing
     * and is not a failure.
     *
     * @param  array<int, int>  $customerIds
     */
    public function renderZoneFile(int $billBatchId, string $period, ?int $zoneId, string $zoneName, array $customerIds): void
    {
        $billBatch = BillBatch::findOrFail($billBatchId);

        $bills = $this->manuscripts->billDataForCustomers($this->orderedCustomers($customerIds), $period);

        if ($bills === []) {
            return;
        }

        [$content, $pageCount] = $this->renderPdf($bills, $billBatch->density, $billBatch->template);

        $path = $this->basePath($billBatch).'/zone-'.($zoneId ?? 'unzoned').'.pdf';
        Storage::disk($this->disk())->put($path, $content);

        BillBatchFile::updateOrCreate(
            ['bill_batch_id' => $billBatch->id, 'kind' => 'zone', 'zone_id' => $zoneId],
            [
                'zone_name' => $zoneName,
                'disk' => $this->disk(),
                'path' => $path,
                'bill_count' => count($bills),
                'page_count' => $pageCount,
                'size_bytes' => strlen($content),
            ],
        );
    }

    /**
     * Renders the single bulk PDF — every recipient, already zone-then-name
     * ordered by the caller.
     *
     * @param  array<int, int>  $customerIds  In final render order.
     */
    public function renderBulkFile(int $billBatchId, string $period, array $customerIds): void
    {
        $billBatch = BillBatch::findOrFail($billBatchId);

        $bills = $this->manuscripts->billDataForCustomers($this->orderedCustomers($customerIds), $period);

        if ($bills === []) {
            return;
        }

        [$content, $pageCount] = $this->renderPdf($bills, $billBatch->density, $billBatch->template);

        $path = $this->basePath($billBatch).'/bulk.pdf';
        Storage::disk($this->disk())->put($path, $content);

        BillBatchFile::updateOrCreate(
            ['bill_batch_id' => $billBatch->id, 'kind' => 'bulk', 'zone_id' => null],
            [
                'zone_name' => null,
                'disk' => $this->disk(),
                'path' => $path,
                'bill_count' => count($bills),
                'page_count' => $pageCount,
                'size_bytes' => strlen($content),
            ],
        );
    }

    /**
     * catch() callback body — records the first hard failure's message so
     * the UI can show it. Does NOT set a terminal status; finalize() (which
     * always runs after) decides completed/partial/failed from what
     * actually landed on disk.
     */
    public function handleBatchFailed(int $billBatchId, Throwable $e): void
    {
        BillBatch::query()
            ->where('id', $billBatchId)
            ->whereNull('error_message')
            ->update(['error_message' => Str::limit($e->getMessage(), 1900)]);

        report($e);
    }

    /**
     * finally() callback body — always runs once the batch settles. Builds
     * the convenience ZIP from whatever per-zone PDFs exist, then sets the
     * final status:
     *   - failed:    bulk PDF missing, or zero zone PDFs.
     *   - partial:   some zone job failed / fewer zone PDFs than expected,
     *                but the bulk PDF and >=1 zone PDF exist.
     *   - completed: bulk PDF + every expected zone PDF present.
     */
    public function finalize(int $billBatchId, int $failedJobs = 0, int $totalJobs = 0): void
    {
        $billBatch = BillBatch::with('files')->find($billBatchId);

        if (! $billBatch) {
            return;
        }

        // The run was cancelled mid-flight — don't resurrect it as
        // failed/partial. Just make sure no half-rendered artifact lingers
        // (cancel() also does this; whichever runs last wins).
        if ($billBatch->status === 'cancelled') {
            $this->discardArtifacts($billBatch);

            return;
        }

        $zoneFiles = $billBatch->files->where('kind', 'zone')->values();
        $hasBulk = $billBatch->files->firstWhere('kind', 'bulk') !== null;
        $hasZip = $billBatch->files->firstWhere('kind', 'zip') !== null;

        if ($zoneFiles->isNotEmpty() && ! $hasZip) {
            $this->buildZip($billBatch, $zoneFiles);
        }

        $status = match (true) {
            ! $hasBulk || $zoneFiles->isEmpty() => 'failed',
            $failedJobs > 0 || $zoneFiles->count() < $billBatch->total_zones => 'partial',
            default => 'completed',
        };

        $billBatch->update([
            'status' => $status,
            'completed_at' => now(),
            'error_message' => $status === 'failed'
                ? ($billBatch->error_message ?? 'Bill generation produced no complete artifact.')
                : $billBatch->error_message,
        ]);
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Customer>
     */
    private function orderedCustomers(array $ids): Collection
    {
        $byId = Customer::query()->with('zone')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn (int $id): ?Customer => $byId->get($id))
            ->filter()
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $bills
     * @return array{0: string, 1: int|null}  [pdf bytes, page count or null]
     */
    private function renderPdf(array $bills, int $density, string $template): array
    {
        // The entire point of moving off the web request — render generously.
        ini_set('memory_limit', '1024M');
        set_time_limit(0);

        $pdf = Pdf::loadView('pdf.bills._grid', [
            'bills' => $bills,
            'density' => $density,
            'template' => $template,
        ])->setPaper('a4', 'portrait');

        $content = $pdf->output();

        $pageCount = null;

        try {
            $pageCount = $pdf->getDomPDF()->getCanvas()->get_page_count();
        } catch (Throwable) {
            // dompdf internals — page count is a nice-to-have, never fatal.
        }

        return [$content, $pageCount];
    }

    /**
     * @param  Collection<int, BillBatchFile>  $zoneFiles
     */
    private function buildZip(BillBatch $billBatch, Collection $zoneFiles): void
    {
        if (! class_exists(ZipArchive::class)) {
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'billzip');

        if ($tmp === false) {
            return;
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return;
            }

            foreach ($zoneFiles as $file) {
                if (Storage::disk($file->disk)->exists($file->path)) {
                    $zip->addFromString($file->downloadName(), Storage::disk($file->disk)->get($file->path));
                }
            }

            $zip->close();

            $path = $this->basePath($billBatch).'/by-zone.zip';
            Storage::disk($this->disk())->put($path, (string) file_get_contents($tmp));

            BillBatchFile::updateOrCreate(
                ['bill_batch_id' => $billBatch->id, 'kind' => 'zip', 'zone_id' => null],
                [
                    'zone_name' => null,
                    'disk' => $this->disk(),
                    'path' => $path,
                    'bill_count' => (int) $zoneFiles->sum('bill_count'),
                    'page_count' => null,
                    'size_bytes' => (int) (Storage::disk($this->disk())->size($path) ?? 0),
                ],
            );
        } finally {
            @unlink($tmp);
        }
    }

    private function basePath(BillBatch $billBatch): string
    {
        $tenantId = (string) (tenant()?->getTenantKey() ?? 'central');

        return "bill-batches/{$tenantId}/{$billBatch->uuid}";
    }
}
