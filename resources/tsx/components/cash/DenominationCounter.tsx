import { formatCurrency } from '@/lib/formatCurrency';

export type DenominationCounts = Record<string, string>;

export function denominationTotal(counts: DenominationCounts): number {
    return Object.entries(counts).reduce((sum, [value, count]) => sum + Number(value) * (Number(count) || 0), 0);
}

/**
 * Note-by-note cash count (docs/plans/cash-controls.md) — CFA notes and
 * coins from App\Services\CashBookService::DENOMINATIONS. Used by the daily
 * income form, the cashier's receive form and the till's day close; the
 * total it shows is what the server checks the declared figure against.
 */
export function DenominationCounter({
    denominations,
    counts,
    onChange,
}: {
    denominations: number[];
    counts: DenominationCounts;
    onChange: (counts: DenominationCounts) => void;
}) {
    const total = denominationTotal(counts);

    return (
        <div className="rounded-lg border border-slate-200 p-3">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                {denominations.map((value) => (
                    <label key={value} className="flex flex-col gap-1 text-xs font-medium text-slate-600">
                        {value.toLocaleString()} ×
                        <input
                            type="number"
                            min="0"
                            inputMode="numeric"
                            value={counts[String(value)] ?? ''}
                            onChange={(e) => onChange({ ...counts, [String(value)]: e.target.value })}
                            className="w-full rounded-md border-0 px-2 py-1.5 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600"
                            placeholder="0"
                        />
                    </label>
                ))}
            </div>
            <div className="mt-2 text-right text-sm text-slate-600">
                Counted: <span className="font-semibold text-slate-900">{formatCurrency(total)}</span>
            </div>
        </div>
    );
}
