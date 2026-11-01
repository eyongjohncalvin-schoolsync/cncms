interface VipBillingFieldProps {
    billed: boolean;
    error?: string;
    onChange: (billed: boolean) => void;
}

/**
 * "Bill this VIP" — shown only for a VIP. Unticked (the default), the
 * monthly run charges them nothing new and they get no bill slip or
 * reminder (owner, 2026-11-01, as in the old system).
 */
export function VipBillingField({ billed, error, onChange }: VipBillingFieldProps) {
    return (
        <div className="sm:col-span-2">
            <label className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                <input
                    type="checkbox"
                    name="vip_billed"
                    checked={billed}
                    onChange={(e) => onChange(e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded border-slate-300"
                />
                <span>
                    <span className="font-medium text-slate-900">Bill this VIP</span>
                    <span className="mt-0.5 block text-xs text-slate-500">
                        {billed
                            ? 'Billed every month like any other customer.'
                            : 'Not billed: no monthly charge, bill slip or reminder. Any old balance stays on record.'}
                    </span>
                </span>
            </label>
            {error && <p className="mt-2 text-xs text-red-600">{error}</p>}
        </div>
    );
}
