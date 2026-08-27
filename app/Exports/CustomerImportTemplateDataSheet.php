<?php

declare(strict_types=1);

namespace App\Exports;

use App\Imports\CustomersImport;
use App\Models\Zone;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The "Customers" sheet of the blank customer import template
 * (App\Exports\CustomerImportTemplateExport). Headings come straight from
 * CustomersImport::COLUMNS — the exact same contract CustomersImport uses
 * to read a real upload back in, so this sheet can never drift out of sync
 * with what the importer actually expects. Two example rows use real
 * `level`/`status` enum values (StoreCustomerRequest::rules()) and, when
 * the tenant has at least one zone, that zone's real name — otherwise a
 * placeholder that points the user at the "Valid Zones" sheet.
 */
final class CustomerImportTemplateDataSheet implements Export, FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        private readonly ?Zone $exampleZone,
    ) {}

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return CustomersImport::COLUMNS;
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $zoneName = $this->exampleZone->name ?? 'See the "Valid Zones" sheet';

        return [
            ['Jane Doe', '677440670', 'normal', 'Main Street', $zoneName, '2500.00', '0.00', 'active'],
            ['John Smith', '672528022', 'Vip', 'Junction Road', $zoneName, '5000.00', '1500.00', 'active'],
        ];
    }

    public function title(): string
    {
        return 'Customers';
    }
}
