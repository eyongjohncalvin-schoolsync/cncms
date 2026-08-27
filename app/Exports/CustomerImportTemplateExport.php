<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Zone;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Blank customer_upload_main.xlsx template for
 * GET /customers/import/template (CustomerController::importTemplate()) —
 * the download-first counterpart to CustomerController::import() /
 * App\Services\CustomerImportService. Two sheets: "Customers" (the exact
 * column headers CustomersImport::COLUMNS expects, plus example rows) and
 * "Valid Zones" (every current zone name verbatim, since `zone` must match
 * an existing zone name exactly — see CustomerImportTemplateZonesSheet's
 * doc comment).
 *
 * Must implement `Export` explicitly (not just `WithMultipleSheets`) —
 * Maatwebsite\Excel\Excel::download()/Writer::export() both type-hint the
 * strict `Export $export` marker, and WithMultipleSheets alone does not
 * satisfy it (same v4 strictness CustomersImport's doc comment notes for
 * `Import`).
 */
final class CustomerImportTemplateExport implements Export, WithMultipleSheets
{
    /**
     * @param  Collection<int, Zone>  $zones
     */
    public function __construct(
        private readonly Collection $zones,
    ) {}

    /**
     * @return array<int, Export>
     */
    public function sheets(): array
    {
        return [
            new CustomerImportTemplateDataSheet($this->zones->first()),
            new CustomerImportTemplateZonesSheet($this->zones),
        ];
    }
}
