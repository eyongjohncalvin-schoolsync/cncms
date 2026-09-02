<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One tab of the customer-record workbook (App\Exports\CustomerRecordExport)
 * — a generic "title + heading row + rows" sheet so every section
 * (Profile, Payments, Manuscripts, ...) reuses the exact same class rather
 * than a bespoke Export per section. The data is already flattened to
 * scalars by App\Services\CustomerRecordExportService::gather(), so this
 * class does no shaping of its own.
 */
final class CustomerRecordSheet implements Export, FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  string  $title  the sheet tab name (Excel caps this at 31 chars)
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    public function title(): string
    {
        // Excel hard-limits a sheet name to 31 characters.
        return mb_substr($this->title, 0, 31);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        // maatwebsite/excel drops a completely empty FromArray; a single
        // spacer row keeps an empty section (no payments, no complaints, ...)
        // present as a real, visible tab rather than silently missing.
        return $this->rows === [] ? [array_fill(0, max(1, count($this->headings)), null)] : $this->rows;
    }
}
