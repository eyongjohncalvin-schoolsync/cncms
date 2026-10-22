<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ReceiptBlock;
use App\Services\ReceiptBookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Builds and stores a printed receipt book's PDF (App\Services\
 * ReceiptBookService::generate()) — up to 100 leaves, a QR each, too slow to
 * render inside the request that clicked Print. Runs on the `bills` queue
 * with the other PDF work; tenancy is carried by QueueTenancyBootstrapper.
 */
class GenerateReceiptBookPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        public readonly int $receiptBlockId,
    ) {}

    public function handle(ReceiptBookService $books): void
    {
        $block = ReceiptBlock::query()->find($this->receiptBlockId);

        if ($block !== null) {
            $books->generate($block);
        }
    }

    public function failed(Throwable $exception): void
    {
        ReceiptBlock::query()->whereKey($this->receiptBlockId)->update([
            'pdf_status' => 'failed',
            'pdf_error' => mb_substr($exception->getMessage(), 0, 500),
        ]);
    }
}
