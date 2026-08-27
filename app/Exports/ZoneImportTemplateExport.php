<?php

declare(strict_types=1);

namespace App\Exports;

use App\Imports\ZonesImport;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Blank zone_upload.xlsx template for GET /zones/import/template
 * (ZoneController::importTemplate()) — the download-first counterpart to
 * ZoneController::import() / App\Services\ZoneImportService. Headings come
 * straight from ZonesImport::COLUMNS, the exact same contract ZonesImport
 * uses to read a real upload back in, so this template can never drift out
 * of sync with what the importer actually expects. Example rows use the
 * real coded naming pattern documented in cncms-context's "Core tables"
 * section (e.g. `THR01 (3/CORNERS)`).
 */
final class ZoneImportTemplateExport implements Export, FromArray, ShouldAutoSize, WithHeadings
{
    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ZonesImport::COLUMNS;
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return [
            ['THR01 (3/CORNERS)', 'KUMBA 3'],
            ['AR01(JUNCTION)', 'KUMBA 3'],
        ];
    }
}
