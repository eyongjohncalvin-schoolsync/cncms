import { useForm } from '@inertiajs/react';
import { FormEvent, useEffect } from 'react';
import { IconAlertTriangle } from '@tabler/icons-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { formatCurrency } from '@/lib/formatCurrency';

/**
 * The danger modal for archiving a customer with billing history
 * (customer-deletion deliberation, 2026-08-29). Archiving is reversible and
 * moves no money, so it is amber, not red — but it still gates on a
 * type-the-name confirmation AND a required reason (a permanent audit
 * note), matching the "confirm button stays disabled until both" rule from
 * the synthesis. It WARNS about side effects (pending adjustments, prepaid
 * credit) without blocking on them.
 *
 * PATCH /customers/{uuid}/archive with { name, reason } — the server
 * (ArchiveCustomerRequest) re-checks the name match, so this is a UX gate,
 * not the only guard.
 */
interface ArchiveCustomerModalProps {
    open: boolean;
    onClose: () => void;
    customer: {
        uuid: string;
        name: string;
        /** Latest manuscript figures, when available (the Show page passes them; list rows don't). */
        arrears?: string | null;
        credit?: string | null;
        pendingAdjustments?: number;
    } | null;
}

export function ArchiveCustomerModal({ open, onClose, customer }: ArchiveCustomerModalProps) {
    const { data, setData, patch, processing, errors, reset, clearErrors } = useForm({
        name: '',
        reason: '',
    });

    // Reset whenever the modal is (re)opened for a customer, so a previous
    // attempt's typed name/reason never leaks into the next one.
    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, customer?.uuid]);

    if (!customer) return null;

    const nameMatches = data.name.trim() === customer.name;
    const reasonFilled = data.reason.trim().length >= 3;
    const canSubmit = nameMatches && reasonFilled && !processing;

    const arrears = customer.arrears != null ? Number(customer.arrears) : 0;
    const credit = customer.credit != null ? Number(customer.credit) : 0;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!canSubmit || !customer) return;

        patch(`/customers/${customer.uuid}/archive`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <Modal open={open} onClose={onClose} title={`Archive ${customer.name}?`}>
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <IconAlertTriangle size={18} stroke={1.75} className="mt-0.5 shrink-0 text-amber-600" />
                    <p>
                        Archiving removes this customer from the active register, from future manuscript runs, and from the
                        dashboard. <span className="font-semibold">Their billing history is kept</span> and stays visible on
                        their page for audit. You can restore them at any time.
                    </p>
                </div>

                {(arrears > 0 || credit > 0 || (customer.pendingAdjustments ?? 0) > 0) && (
                    <ul className="flex flex-col gap-1 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                        {arrears > 0 && (
                            <li>
                                Outstanding arrears of{' '}
                                <span className="font-semibold text-slate-900">{formatCurrency(String(arrears))}</span> stay
                                frozen at their current value — archiving does not write them off.
                            </li>
                        )}
                        {credit > 0 && (
                            <li>
                                Prepaid credit of{' '}
                                <span className="font-semibold text-slate-900">{formatCurrency(String(credit))}</span> is kept
                                and frozen.
                            </li>
                        )}
                        {(customer.pendingAdjustments ?? 0) > 0 && (
                            <li>
                                {customer.pendingAdjustments} pending arrears adjustment
                                {customer.pendingAdjustments === 1 ? '' : 's'} will be left pending.
                            </li>
                        )}
                    </ul>
                )}

                <div className="flex flex-col gap-1">
                    <label htmlFor="archive-name" className="text-sm font-medium text-slate-700">
                        This customer has billing history. Type their name exactly to confirm:
                    </label>
                    <input
                        id="archive-name"
                        type="text"
                        autoComplete="off"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder={customer.name}
                        className={`rounded-lg border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-inset ${
                            data.name.length > 0 && !nameMatches
                                ? 'ring-red-400 focus:ring-red-500'
                                : 'ring-slate-300 focus:ring-amber-600'
                        }`}
                    />
                    {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                </div>

                <div className="flex flex-col gap-1">
                    <label htmlFor="archive-reason" className="text-sm font-medium text-slate-700">
                        Reason for archiving <span className="text-red-500">*</span>
                    </label>
                    <textarea
                        id="archive-reason"
                        rows={3}
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                        placeholder='e.g. "Moved out of Kumba 3, line cut Apr 2026, confirmed by agent Etienne." — this is a permanent audit record.'
                        className={`rounded-lg border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-amber-600 ${
                            errors.reason ? 'ring-red-400 focus:ring-red-500' : ''
                        }`}
                    />
                    {errors.reason && <p className="text-xs text-red-600">{errors.reason}</p>}
                </div>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="warning" disabled={!canSubmit}>
                        Archive customer
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
