<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BillBatch;
use App\Models\BillBatchFile;
use App\Models\Manuscript;
use App\Services\BillBatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asynchronous (queued) bill generation (owner's 2026-08-30 ask) — replaces
 * the synchronous `GET /manuscripts/bills` (ManuscriptController::downloadBills,
 * removed) that rendered every active customer's bill PDF inside the web
 * request behind a 1024M / 180s ceiling. This kicks off a background
 * Bus::batch() (App\Services\BillBatchService) and streams the finished
 * artifacts once the queue worker has produced them.
 *
 * The batch LIST + status is folded into ManuscriptController::index()'s
 * Inertia props (`billBatches`), polled from the Manuscripts page with
 * router.reload({ only: ['billBatches'] }) — no separate list endpoint.
 *
 * Both actions are gated by ManuscriptPolicy::export (super/admin/manager),
 * the same gate the register export and the old synchronous bills download
 * used.
 */
class BillBatchController extends Controller
{
    public function __construct(
        private readonly BillBatchService $batches,
    ) {}

    /**
     * Kicks off a generation run for the period (+ optional zone/status/
     * search filters, same keys as the register export). Redirects back to
     * the Manuscripts page, where the new batch shows up as `queued` and
     * the page polls it to `completed`.
     */
    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('export', Manuscript::class);

        $filters = $request->only(['period', 'zone_uuid', 'status', 'search']);
        $period = (string) ($filters['period'] ?? now()->format('Y-m'));

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            return back()->with('error', "Invalid period \"{$period}\" — expected format YYYY-MM.");
        }

        try {
            $this->batches->dispatch($period, $filters, Auth::id());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return redirect()
            ->route('manuscripts.index', ['period' => $period])
            ->with('success', "Bill generation for {$period} started — it runs in the background. Download links appear below once it's ready (keep the queue worker running).");
    }

    /**
     * Streams one stored artifact PDF/ZIP. {billBatch} and {billBatchFile}
     * both route-model-bind by uuid; the file is verified to belong to the
     * batch before anything is streamed.
     */
    public function download(BillBatch $billBatch, BillBatchFile $billBatchFile): StreamedResponse
    {
        $this->authorize('export', Manuscript::class);

        abort_unless($billBatchFile->bill_batch_id === $billBatch->id, 404);
        abort_unless(Storage::disk($billBatchFile->disk)->exists($billBatchFile->path), 404);

        return Storage::disk($billBatchFile->disk)->download($billBatchFile->path, $billBatchFile->downloadName());
    }
}
