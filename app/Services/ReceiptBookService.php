<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Support\PaymentReceiptLink;
use App\Support\QrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Renders a system-printed receipt book (docs/plans/cash-controls.md,
 * wave 2): A4 sheets of numbered leaves, four to a page. Each leaf is an
 * office stub (stays in the book) beside the customer's copy, both carrying
 * the same system-issued number; the customer copy has its own QR to the
 * public check page, so a customer can see whether — and for how much — the
 * payment was recorded.
 */
class ReceiptBookService
{
    public const int LEAVES_PER_PAGE = 4;

    public function pdf(ReceiptBlock $block): DomPdf
    {
        $block->loadMissing('holder');
        $company = Company::cached();

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
