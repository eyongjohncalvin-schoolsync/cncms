<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateReceiptBookPdfJob;
use App\Models\Company;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Support\PaymentReceiptLink;
use App\Support\QrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a system-printed receipt book (docs/plans/cash-controls.md,
 * wave 2): A4 sheets of numbered leaves, four to a page. Each leaf is an
 * office stub (stays in the book) beside the customer's copy, both carrying
 * the same system-issued number; the customer copy has its own QR to the
 * public check page, so a customer can see whether — and for how much — the
 * payment was recorded.
 *
 * Rendering a whole book (up to 100 QR codes) can take longer than a web
 * request is allowed to run, so it is never done in the request: queue()
 * hands it to GenerateReceiptBookPdfJob, which calls generate() and stores
 * the file; the Print button downloads the stored file.
 */
class ReceiptBookService
{
    public const int LEAVES_PER_PAGE = 4;

    /** Mark the book's PDF as being prepared and hand it to the queue. */
    public function queue(ReceiptBlock $block): void
    {
        $block->update(['pdf_status' => 'pending', 'pdf_error' => null]);

        GenerateReceiptBookPdfJob::dispatch($block->id)->onQueue('bills')->afterCommit();
    }

    /** Render and store the PDF — called by the job, never by a request. */
    public function generate(ReceiptBlock $block): void
    {
        $disk = (string) config('filesystems.default', 'local');
        $tenantId = (string) (tenant()?->getTenantKey() ?? 'central');
        $path = "receipt-books/{$tenantId}/{$block->uuid}.pdf";

        Storage::disk($disk)->put($path, $this->pdf($block)->output());

        $block->update([
            'pdf_status' => 'ready',
            'pdf_path' => $path,
            'pdf_disk' => $disk,
            'pdf_error' => null,
            'pdf_generated_at' => now(),
        ]);
    }

    public function isReady(ReceiptBlock $block): bool
    {
        return $block->pdf_status === 'ready'
            && $block->pdf_path !== null
            && Storage::disk((string) $block->pdf_disk)->exists($block->pdf_path);
    }

    public function pdf(ReceiptBlock $block): DomPdf
    {
        $block->loadMissing('holder');
        $company = Company::forBranch($block->branch_id);

        $leaves = $block->leaves()->get()->map(fn (ReceiptLeaf $leaf): array => [
            'number' => $leaf->number,
            'status' => $leaf->status,
            'qr' => QrCode::pngDataUri(PaymentReceiptLink::checkFor($leaf->number), 3),
        ]);

        return Pdf::loadView('pdf.receipt-book', [
            'block' => $block,
            'pages' => $leaves->chunk(self::LEAVES_PER_PAGE),
            'holderName' => $block->holder?->name,
            'company' => [
                'name' => $company?->name,
                'location' => $company?->location,
                'phone' => $company?->phone,
                'momo_number' => $company?->momo_number,
            ],
            'logo' => $company?->logoDataUri(),
        ])->setPaper('a4', 'portrait');
    }
}
