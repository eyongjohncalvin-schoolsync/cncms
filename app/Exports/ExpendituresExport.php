<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Expenditure;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One month's expenditures as an .xlsx — the export of the expenditures
 * index (docs/plans/expenditures-and-spending-controls.md, item 7), with
 * the same filters. Amounts are real numbers; voided rows are included and
 * marked so the sheet reconciles with the screen.
 */
final class ExpendituresExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, Expenditure>  $expenditures
     */
    public function __construct(
        private readonly string $month,
        private readonly Collection $expenditures,
    ) {}

    public function title(): string
    {
        return 'Expenditures '.$this->month;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Date', 'Bon', 'Category', 'Description', 'Amount', 'Source', 'Status', 'Recorded by', 'Relates to', 'Notes'];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return $this->expenditures->map(fn (Expenditure $expenditure): array => [
            $expenditure->spent_at?->toDateString(),
            $expenditure->voucher?->number,
            $expenditure->category?->name,
            $expenditure->description,
            (float) $expenditure->amount,
            $expenditure->source,
            $expenditure->status,
            $expenditure->user?->name,
            $expenditure->relates_to_period,
            $expenditure->notes,
        ])->values()->all();
    }
}
