<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Expenditure;
use App\Services\ExpenseControlService;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The expense register for a date range — every expense, whatever its
 * status, with who recorded, approved, rejected or voided it and its red
 * flags. Read in chunks straight from the query, so a large year exports
 * without loading everything into memory.
 */
final class ExpenseRegisterExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private readonly string $from,
        private readonly string $to,
    ) {}

    /**
     * @return Builder<Expenditure>
     */
    public function query(): Builder
    {
        return Expenditure::query()
            ->with(['category', 'user', 'supplier', 'service', 'firstApprover', 'approver', 'rejecter', 'voider'])
            ->whereBetween('spent_at', [$this->from, $this->to])
            ->orderBy('spent_at')
            ->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Date', 'Category', 'Description', 'Amount (FCFA)', 'Status', 'Paid by', 'Payment ref', 'Supplier', 'Service',
            'Receipt', 'Recorded by', 'Recorded at', 'First approval', 'Approved by', 'Approved at', 'Rejected by', 'Rejection reason',
            'Voided by', 'Void reason', 'Flags', 'Reference',
        ];
    }

    /**
     * @param  Expenditure  $expenditure
     * @return list<string|float|null>
     */
    public function map($expenditure): array
    {
        return [
            $expenditure->spent_at?->toDateString(),
            $expenditure->category?->name,
            $expenditure->description,
            (float) $expenditure->amount,
            str_replace('_', ' ', (string) $expenditure->status),
            $expenditure->payment_method,
            $expenditure->payment_ref,
            $expenditure->supplier?->name,
            $expenditure->service?->name ?? 'Shared',
            $expenditure->receipt_path !== null ? 'Yes' : 'No',
            $expenditure->user?->name,
            $expenditure->created_at?->toDateTimeString(),
            $expenditure->firstApprover?->name,
            $expenditure->approved_by !== null ? $expenditure->approver?->name : ($expenditure->auto_approved ? 'Automatic' : null),
            $expenditure->approved_at?->toDateTimeString(),
            $expenditure->rejecter?->name,
            $expenditure->rejection_reason,
            $expenditure->voider?->name,
            $expenditure->void_reason,
            collect($expenditure->flags ?? [])->map(fn (string $flag): string => ExpenseControlService::FLAG_LABELS[$flag] ?? $flag)->implode('; '),
            $expenditure->reference_no,
        ];
    }

    public function title(): string
    {
        return 'Expenses';
    }
}
