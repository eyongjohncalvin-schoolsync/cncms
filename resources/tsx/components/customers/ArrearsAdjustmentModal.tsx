import { FormEvent, ReactNode, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { IconEraser, IconScale } from '@tabler/icons-react';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';
import type {
    ArrearsAdjustmentDirection,
    ArrearsAdjustmentReasonCategory,
    ArrearsAdjustmentTarget,
    Customer,
    CustomerManuscriptSummary,
} from '@/types';

const reasonCategoryLabels: Record<ArrearsAdjustmentReasonCategory, string> = {
    legacy_migration_error: 'Legacy migration error',
    billing_error: 'Billing error',
    goodwill_service_outage: 'Goodwill — service outage',
    bad_debt_writeoff: 'Bad debt write-off',
    credit_clawback: 'Credit clawback',
    other: 'Other',
    credit_correction: 'Credit correction',
    duplicate_credit: 'Duplicate credit',
    migration_credit_error: 'Migration credit error',
};

// Arrears corrections and credit corrections offer different reason menus —
// the credit-specific categories were added alongside the `target = 'credit'`
// path (2026-08-30). The shared 'other' is available to both.
const arrearsReasonOrder: ArrearsAdjustmentReasonCategory[] = [
    'legacy_migration_error',
    'billing_error',
    'goodwill_service_outage',
    'bad_debt_writeoff',
    'credit_clawback',
    'other',
];

const creditReasonOrder: ArrearsAdjustmentReasonCategory[] = [
    'credit_correction',
    'duplicate_credit',
    'migration_credit_error',
    'billing_error',
    'other',
];

type CustomerForAdjustment = Pick<Customer, 'uuid' | 'name'> & {
    manuscript?:
        | (Pick<CustomerManuscriptSummary, 'total_arrears'> & Partial<Pick<CustomerManuscriptSummary, 'credit'>>)
        | null;
};

function currentPeriod(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function toNumberOrNull(value: string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * The Arrears Adjustment request modal — reached from Customers/Show.tsx's
 * action row (alongside Print Bill/Edit), and, as of the 2026-08-27
 * addendum in this feature's design doc, also from Manuscripts/Index.tsx's
 * per-row actions and Payments/Show.tsx's header actions. Structurally a
 * Modal, never a page: no customer picker (context is already the
 * customer), no frequency selector — nothing here can be mistaken for the
 * Payment form.
 *
 * `trigger` is an optional render prop letting a caller swap in its own
 * button while reusing everything else here unchanged.
 *
 * Controlled mode: pass `open` + `onClose` and the component renders ONLY
 * the dialog (no trigger of its own) — the caller owns the open state. This
 * is mandatory when the launch control lives inside something that unmounts
 * on click (e.g. a Headless UI menu / our <Dropdown>): an internal `open`
 * state would be destroyed together with the menu the instant the item is
 * clicked, so the dialog "flashes and vanishes". See Manuscripts/Index.tsx.
 *
 * Target toggle (2026-08-30 addendum): a correction lands on EITHER the
 * customer's `total_arrears` OR their loose `credit` figure — the latter is
 * the fallback for the 2026-08 baseline-credit corruption (see
 * arrears-adjustment.md). A credit correction touches ONLY the loose credit
 * amount, never prepaid coverage (prepaid_months_remaining / prepaid_rate).
 * "Clear all arrears" / "Clear credit" are pure pre-fill conveniences — they
 * set direction + amount and stop; Submit Request is still a separate click,
 * and every request still goes through the full maker-checker workflow.
 */
export function ArrearsAdjustmentModal({
    customer,
    trigger,
    open: controlledOpen,
    onClose: controlledOnClose,
}: {
    customer: CustomerForAdjustment;
    trigger?: (open: () => void) => ReactNode;
    /** Controlled mode — when provided, the caller owns the open state and no trigger is rendered. */
    open?: boolean;
    onClose?: () => void;
}) {
    const isControlled = controlledOpen !== undefined;
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
    const open = isControlled ? controlledOpen : uncontrolledOpen;

    const { data, setData, post, processing, errors, reset } = useForm({
        customer_uuid: customer.uuid,
        target_period: currentPeriod(),
        target: 'arrears' as ArrearsAdjustmentTarget,
        direction: 'decrease' as ArrearsAdjustmentDirection,
        reason_category: 'billing_error' as ArrearsAdjustmentReasonCategory,
        amount: '',
        reason_note: '',
    });

    const isCredit = data.target === 'credit';
    const currentArrears = customer.manuscript?.total_arrears ?? null;
    const currentCredit = customer.manuscript?.credit ?? null;
    const activeBalance = isCredit ? currentCredit : currentArrears;

    // Display-only guidance, not the real ledger math (that only runs once
    // the request is approved). For an arrears target: 'decrease' writes off,
    // 'increase' corrects up. For a credit target: 'increase' claws credit
    // back (reduces it), 'decrease' grants credit (adds to it).
    const balanceAfter = useMemo(() => {
        const balance = toNumberOrNull(activeBalance);
        const amount = Number(data.amount);

        if (balance === null || data.amount === '' || !Number.isFinite(amount) || amount <= 0) {
            return null;
        }

        const reduces = isCredit ? data.direction === 'increase' : data.direction === 'decrease';
        return reduces ? Math.max(0, balance - amount) : balance + amount;
    }, [activeBalance, data.amount, data.direction, isCredit]);

    function switchTarget(next: ArrearsAdjustmentTarget) {
        if (next === data.target) {
            return;
        }
        setData((current) => ({
            ...current,
            target: next,
            // Sensible default direction per target: write-off for arrears,
            // claw-back for credit (the 2026-08 corruption case).
            direction: next === 'credit' ? 'increase' : 'decrease',
            reason_category: next === 'credit' ? 'credit_correction' : 'billing_error',
            amount: '',
        }));
    }

    // "Clear all arrears" / "Clear credit" quick-fill — pre-fills
    // direction + amount for the single most common case (zeroing the whole
    // balance on the active side), then stops. reason_category / reason_note
    // are left for the user (a correction still needs a real justification).
    // Reads the same figure shown in this modal's own "Current" line — the
    // approval-time staleness re-check re-derives the true server-side value
    // from a fresh snapshot regardless of what amount the form sends.
    function clearActiveBalance() {
        const balance = toNumberOrNull(activeBalance);
        if (balance === null || balance <= 0) {
            return;
        }
        setData((current) => ({
            ...current,
            direction: isCredit ? 'increase' : 'decrease',
            amount: String(activeBalance),
        }));
    }

    function close() {
        reset();
        if (isControlled) {
            controlledOnClose?.();
        } else {
            setUncontrolledOpen(false);
        }
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        post('/arrears-adjustments', {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    }

    const reasonOrder = isCredit ? creditReasonOrder : arrearsReasonOrder;
    const activeBalanceNumber = toNumberOrNull(activeBalance);

    return (
        <>
            {!isControlled &&
                (trigger ? (
                    trigger(() => setUncontrolledOpen(true))
                ) : (
                    <button
                        type="button"
                        onClick={() => setUncontrolledOpen(true)}
                        className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-purple-600/20 transition-all duration-150 hover:bg-purple-700 hover:shadow-purple-600/30 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-purple-600 active:scale-[0.98]"
                    >
                        <IconScale size={16} stroke={1.75} />
                        Adjust Arrears
                    </button>
                ))}

            <Modal open={open} onClose={close} title="Request Ledger Adjustment">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="rounded-lg border border-purple-200 bg-purple-50 p-3 text-sm text-purple-900">
                        This does not record a payment. No money changes hands here — this adjusts{' '}
                        <span className="font-medium">{customer.name}</span>&apos;s{' '}
                        {isCredit ? 'credit' : 'arrears'} balance directly and will be visible on their record and in
                        reports.
                    </div>

                    {/* Target toggle — which side of the ledger this corrects. */}
                    <div className="flex flex-col gap-1.5">
                        <span className="text-sm font-medium text-slate-700">What are you correcting?</span>
                        <div className="grid grid-cols-2 gap-2">
                            {(['arrears', 'credit'] as ArrearsAdjustmentTarget[]).map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    onClick={() => switchTarget(option)}
                                    className={`flex flex-col items-start gap-0.5 rounded-lg border px-3 py-2 text-left text-sm transition-colors ${
                                        data.target === option
                                            ? 'border-purple-500 bg-purple-50 text-purple-900 ring-1 ring-purple-500'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    <span className="font-semibold capitalize">{option}</span>
                                    <span className="text-xs text-slate-500">
                                        {option === 'arrears'
                                            ? currentArrears === null
                                                ? 'no figure yet'
                                                : formatCurrency(currentArrears)
                                            : currentCredit === null
                                              ? 'no figure yet'
                                              : formatCurrency(currentCredit)}
                                    </span>
                                </button>
                            ))}
                        </div>
                        {isCredit && (
                            <p className="text-xs text-slate-500">
                                Corrects only the loose credit figure — not prepaid coverage (prepaid months / rate).
                            </p>
                        )}
                    </div>

                    <SelectInput
                        id="reason_category"
                        label="Reason category"
                        value={data.reason_category}
                        onChange={(e) => setData('reason_category', e.target.value as ArrearsAdjustmentReasonCategory)}
                        error={errors.reason_category}
                        required
                    >
                        {reasonOrder.map((key) => (
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
                            {isCredit ? (
                                <>
                                    <option value="increase">Claw back (reduce credit)</option>
                                    <option value="decrease">Grant (increase credit)</option>
                                </>
                            ) : (
                                <>
                                    <option value="decrease">Decrease (write off)</option>
                                    <option value="increase">Increase (correct up)</option>
                                </>
                            )}
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

                    {activeBalanceNumber !== null && activeBalanceNumber > 0 && (
                        <button
                            type="button"
                            onClick={clearActiveBalance}
                            className="inline-flex w-fit items-center justify-center gap-1.5 rounded-full border border-purple-300 bg-purple-50 px-3 py-1.5 text-xs font-semibold text-purple-700 transition-colors hover:bg-purple-100"
                        >
                            <IconEraser size={14} stroke={1.75} />
                            {isCredit ? 'Clear credit' : 'Clear all arrears'} ({formatCurrency(activeBalance as string)})
                        </button>
                    )}

                    <TextInput
                        id="amount"
                        label={`${isCredit ? 'Credit' : 'Arrears'} amount to adjust (FCFA)`}
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

                    {activeBalance !== null && (
                        <div className="flex flex-col gap-1 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <p>
                                Current {isCredit ? 'credit' : 'arrears'}:{' '}
                                <span className="font-semibold text-slate-900">{formatCurrency(activeBalance)}</span>
                            </p>
                            <p>
                                {isCredit ? 'Credit' : 'Balance'} after:{' '}
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
