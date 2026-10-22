import { TextInput } from '@/components/ui/TextInput';
import type { PaymentMethod } from '@/types';

/**
 * How the customer paid + the operator transaction ID — App\Models\Payment::
 * METHODS / methodRules() (docs/plans/cash-controls.md). The ID is required
 * for MTN MoMo and Orange Money: it is what the cashier ticks off against
 * the company wallet statement at the daily remittance.
 */
export const paymentMethodOptions: { value: PaymentMethod; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'mtn_momo', label: 'MTN MoMo' },
    { value: 'orange_money', label: 'Orange Money' },
    { value: 'bank', label: 'Bank' },
];

export function paymentMethodLabel(method: PaymentMethod | null | undefined): string {
    return paymentMethodOptions.find((option) => option.value === method)?.label ?? 'Cash';
}

export function referenceRequired(method: PaymentMethod): boolean {
    return method === 'mtn_momo' || method === 'orange_money';
}

export function PaymentMethodFields({
    method,
    reference,
    onMethodChange,
    onReferenceChange,
    methodError,
    referenceError,
}: {
    method: PaymentMethod;
    reference: string;
    onMethodChange: (method: PaymentMethod) => void;
    onReferenceChange: (reference: string) => void;
    methodError?: string;
    referenceError?: string;
}) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <span className="text-sm font-medium text-slate-700">
                    Paid by
                    <span className="ml-0.5 text-red-500" aria-hidden="true">
                        *
                    </span>
                </span>
                <div className="inline-flex w-fit flex-wrap rounded-lg bg-slate-100 p-1">
                    {paymentMethodOptions.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            aria-pressed={method === option.value}
                            onClick={() => onMethodChange(option.value)}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                method === option.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                            }`}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
                {methodError && <p className="text-xs text-red-600">{methodError}</p>}
            </div>

            {method !== 'cash' && (
                <TextInput
                    id="method_reference"
                    label={method === 'bank' ? 'Bank reference (optional)' : 'Transaction ID'}
                    value={reference}
                    maxLength={100}
                    onChange={(e) => onReferenceChange(e.target.value)}
                    error={referenceError}
                    required={referenceRequired(method)}
                    hint={referenceRequired(method) ? 'From the customer’s confirmation SMS — checked against the company wallet.' : undefined}
                />
            )}
        </div>
    );
}
