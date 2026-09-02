<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Company;
use App\Models\Manuscript;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The monthly manuscript register as an .xlsx workbook — the spreadsheet
 * half of ManuscriptController::export() (?format=xlsx). Fed the IDENTICAL
 * ManuscriptService::exportData() payload the PDF blade
 * (resources/views/pdf/manuscript.blade.php) renders, with the SAME columns
 * in the same order, so the two downloads can never disagree. The "Expiry"
 * column goes through Manuscript::expiryLabel() — the one shared derivation
 * both exports call.
 *
 * Money columns are written as real numbers (not number_format() strings)
 * so the recipient can sum/filter them in Excel; the PDF keeps them as
 * formatted text because it's a fixed printed artifact.
 *
 * The "Paid" column is intentionally blank in both exports — the manager
 * fills in what each customer actually paid after collecting. "Status" is
 * written in full here (it stays filterable in Excel); the PDF abbreviates
 * it (disconnected -> disc, suspended -> susp) purely to save print width.
 */
final class ManuscriptRegisterExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  array{period: string, company: ?Company, manuscripts: Collection<int, Manuscript>, summary: array<string, mixed>}  $data
     */
    public function __construct(private readonly array $data) {}

    public function title(): string
    {
        return 'Manuscript '.$this->data['period'];
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['No', 'Name', 'Code', 'Zone', 'Bill', 'Arrears', 'Credit', 'Total Bill', 'Paid', 'Status', 'Expiry'];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return $this->data['manuscripts']
            ->values()
            ->map(fn (Manuscript $manuscript, int $index): array => [
                $index + 1,
                $manuscript->customer?->name,
                substr($manuscript->customer?->uuid ?? '', 0, 8),
                $manuscript->customer?->zone?->name,
                (float) $manuscript->bill,
                (float) $manuscript->total_arrears,
                (float) $manuscript->credit,
                (float) $manuscript->total_bill,
                // Blank "Paid" column — filled in by hand after collection, mirrors the PDF register.
                null,
                $manuscript->customer?->status,
                $manuscript->expiryLabel(),
            ])
            ->all();
    }
}
