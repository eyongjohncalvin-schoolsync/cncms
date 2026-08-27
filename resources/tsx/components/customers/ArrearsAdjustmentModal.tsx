import { FormEvent, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { IconScale } from '@tabler/icons-react';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';
import type { ArrearsAdjustmentDirection, ArrearsAdjustmentReasonCategory, Customer, CustomerManuscriptSummary } from '@/types';

const reasonCategoryLabels: Record<ArrearsAdjustmentReasonCategory, string> = {
    legacy_migration_error: 'Legacy migration error',
    billing_error: 'Billing error',
    goodwill_service_outage: 'Goodwill — service outage',
    bad_debt_writeoff: 'Bad debt write-off',
    credit_clawback: 'Credit clawback',
    other: 'Other',
};

const reasonCategoryOrder: ArrearsAdjustmentReasonCategory[] = [
    'legacy_migration_error',
    'billing_error',
    'goodwill_service_outage',
    'bad_debt_writeoff',
    'credit_clawback',
    'other',
];

type CustomerForAdjustment = Pick<Customer, 'uuid' | 'name'> & {
    manuscript?: Pick<CustomerManuscriptSummary, 'total_arrears'> | null;
};

function currentPeriod(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * The Arrears Adjustment request modal — reached from Customers/Show.tsx's
 * action row, alongside Print Bill/Edit. Structurally a Modal, never a page:
 * no customer picker (context is already the customer), no frequency
 * selector — nothing here can be mistaken for the Payment form. Purple
 * accent throughout (this feature's design doc: "confirmed as the one
 * genuinely unclaimed color on that page") since blue/red/amber/slate/green
 * already mean specific things on Customers/Show.tsx.
 */
export function ArrearsAdjustmentModal({ customer }: { customer: CustomerForAdjustment }) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        customer_uuid: customer.uuid,
        target_period: currentPeriod(),
        direction: 'decrease' as ArrearsAdjustmentDirection,
        reason_category: 'billing_error' as ArrearsAdjustmentReasonCategory,
        amount: '',
        reason_note: '',
    });

    const currentBalance = customer.manuscript?.total_arrears ?? null;

    // Mirrors CustomerStatusActions.tsx's reconnect-modal arrearsRemaining
    // calc exactly (this feature's design doc): a simple, display-only
    // guidance figure, not the actual credit/arrears-net calculation
    // App\Services\ManuscriptCalculator performs — that real calculation
    // only ever runs once this request is approved.
    const balanceAfter = useMemo(() => {
        if (currentBalance === null || data.amount === '') {
            return null;
        }

        const balance = Number(currentBalance);
        const amount = Number(data.amount);

        if (!Number.isFinite(balance) || !Number.isFinite(amount) || amount <= 0) {
            return null;
        }

        return data.direction === 'decrease' ? Math.max(0, balance - amount) : balance + amount;
    }, [currentBalance, data.amount, data.direction]);

    function close() {
        reset();
        setOpen(false);
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        post('/arrears-adjustments', {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    }

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-purple-600/20 transition-all duration-150 hover:bg-purple-700 hover:shadow-purple-600/30 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-purple-600 active:scale-[0.98]"
            >
                <IconScale size={16} stroke={1.75} />
                Adjust Arrears
            </button>

            <Modal open={open} onClose={close} title="Request Arrears Adjustment">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="rounded-lg border border-purple-200 bg-purple-50 p-3 text-sm text-purple-900">
                        This does not record a payment. No money changes hands here — this adjusts{' '}
                        <span className="font-medium">{customer.name}</span>&apos;s arrears balance directly and will be visible on
                        their record and in reports.
                    </div>

                    <SelectInput
                        id="reason_category"
                        label="Reason category"
                        value={data.reason_category}
                        onChange={(e) => setData('reason_category', e.target.value as ArrearsAdjustmentReasonCategory)}
                        error={errors.reason_category}
                        required
                    >
                        {reasonCategoryOrder.map((key) => (
                            <option key={key} value={key}>
                                {reasonCategoryLabels[key]}
                            </option>
                        ))}
                    </SelectInput>

                    <div className="grid grid-cols-2 gap-3">
                        <SelectInput
                            id="direction"
                            label="Direction"
                            value={data.direction}
                            onChange={(e) => setData('direction', e.target.value as ArrearsAdjustmentDirection)}
                            error={errors.direction}
                            required
                        >
                            <option value="decrease">Decrease (write off)</option>
                            <option value="increase">Increase (correct up)</option>
                        </SelectInput>
                        <TextInput
                            id="target_period"
                            label="Target period"
                            type="month"
                            value={data.target_period}
                            onChange={(e) => setData('target_period', e.target.value)}
                            error={errors.target_period}
                            required
                        />
                    </div>

                    <TextInput
                        id="amount"
                        label="Arrears amount to adjust (FCFA)"
                        type="number"
                        min="0.01"
                        step="0.01"
                        placeholder="0.00"
                        value={data.amount}
                        onChange={(e) => setData('amount', e.target.value)}
                        error={errors.amount}
                        required
                    />

                    <div className="flex flex-col gap-1">
                        <label htmlFor="reason_note" className="text-sm font-medium text-slate-700">
                            Notes <span className="text-red-500">*</span>
                        </label>
                        <textarea
                            id="reason_note"
                            rows={3}
                            required
                            value={data.reason_note}
                            onChange={(e) => setData('reason_note', e.target.value)}
                            placeholder="Explain why this correction is needed — this is a permanent audit record."
                            className={`rounded-lg border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-purple-600 ${
                                errors.reason_note ? 'ring-red-400 focus:ring-red-500' : ''
                            }`}
                        />
                        {errors.reason_note && <p className="text-xs text-red-600">{errors.reason_note}</p>}
                    </div>

                    {currentBalance !== null && (
                        <div className="flex flex-col gap-1 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <p>
                                Current balance: <span className="font-semibold text-slate-900">{formatCurrency(currentBalance)}</span>
                            </p>
                            <p>
                                Balance after:{' '}
                                <span className="font-semibold text-slate-900">
                                    {balanceAfter === null ? '—' : formatCurrency(String(balanceAfter))}
                                </span>
                            </p>
                            <p className="text-xs text-slate-500">Guidance only — the real figure is set by the billing engine once approved.</p>
                        </div>
                    )}

                    <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button
                            type="button"
                            onClick={close}
                            disabled={processing}
                            className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-all duration-150 hover:bg-slate-50 hover:ring-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-purple-600/20 transition-all duration-150 hover:bg-purple-700 hover:shadow-purple-600/30 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-purple-600 disabled:cursor-not-allowed disabled:opacity-50 active:scale-[0.98]"
                        >
                            {processing && <LoadingSpinner className="h-4 w-4" />}
                            Submit Request
                        </button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
