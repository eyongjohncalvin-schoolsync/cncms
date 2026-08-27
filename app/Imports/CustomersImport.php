<?php

declare(strict_types=1);

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Thin maatwebsite/excel adapter for customer_upload_main.xlsx — see
 * cncms-context's "Bulk Import Formats" section for the documented column
 * layout (name, phone, level, location, zone, bill, others, status; the
 * arrears/Credit/Total Bill columns are read-only/computed and simply
 * ignored on import). All the actual row validation/creation logic lives
 * in App\Services\CustomerImportService; this class only tells the
 * package "row 1 is a heading row" so Excel::toCollection() returns rows
 * keyed by their (snake_cased) column headers instead of numeric indexes.
 *
 * `Import` is maatwebsite/excel v4's required marker interface for
 * anything passed to Excel::toCollection() — WithHeadingRow alone
 * (sufficient in v3) no longer satisfies that method's `?Import $import`
 * type hint.
 */
class CustomersImport implements Import, WithHeadingRow
{
    /**
     * Single source of truth for the exact header row this import expects
     * (order matches customer_upload_main.xlsx). App\Exports\
     * CustomerImportTemplateExport reads this constant to generate the
     * downloadable blank template, so the two can never silently drift
     * apart — change a column here and the template picks it up for free.
     *
     * @var list<string>
     */
    public const array COLUMNS = ['name', 'phone', 'level', 'location', 'zone', 'bill', 'others', 'status'];
}
