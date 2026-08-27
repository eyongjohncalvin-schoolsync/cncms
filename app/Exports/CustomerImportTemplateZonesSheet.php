<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Zone;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The "Valid Zones" reference sheet of the blank customer import template
 * (App\Exports\CustomerImportTemplateExport). Per CustomerImportService's
 * own doc comment, the `zone` column must match an EXISTING zone's name
 * exactly and a mismatched name is reported as a failed row rather than
 * silently creating the zone — so a typo here is the most likely real-world
 * import failure. Listing every current zone name verbatim on its own
 * sheet (rather than only in help text) lets the person filling in the
 * template copy-paste the exact spelling instead of guessing it.
 */
final class CustomerImportTemplateZonesSheet implements Export, FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, Zone>  $zones
     */
    public function __construct(
        private readonly Collection $zones,
    ) {}

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Valid Zone Names'];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return $this->zones
            ->map(fn (Zone $zone): array => [$zone->name])
            ->all();
    }

    public function title(): string
    {
        return 'Valid Zones';
    }
}
