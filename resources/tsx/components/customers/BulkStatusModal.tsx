import { FormEvent, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { reasonLabels, reasonOrder } from '@/components/customers/CustomerStatusActions';
import type { StatusAction } from '@/components/customers/CustomerStatusActions';
import type { Customer, SuspendReason } from '@/types';

/**
 * The Disconnections page's primary interaction: apply one status action to
 * every currently-selected customer at once. Mirrors
 * Payments/Index.tsx's VerifyModal/bulk-verify pattern — one shared
 * reason/note applies to the whole batch (App\Services\
 * CustomerStatusService::disconnectMany()/suspendMany()/reconnectMany()
 * process each selected customer independently server-side and skip
 * whichever ones can't make the transition, so this modal doesn't need to
 * pre-filter the selection itself).
 *
 * `customer_uuids` is injected via transform() at submit time straight from
 * the `customers` prop (the parent's live selection), rather than being
 * mirrored into local useForm state — that sidesteps the exact
 * React-batching stale-closure risk VerifyModal's action toggle hit
 * earlier this session, just via "don't duplicate the source of truth"
 * instead of transform() correcting a toggle made in the same tick.
 */
export function BulkStatusModal({
    action,
    customers,
    onClose,
    defaultNote,
}: {
    action: StatusAction | null;
    customers: Customer[];
    onClose: () => void;
    /**
     * Pre-filled into the shared note field when the modal opens — used by
     * the Disconnections page's "flagged for non-payment" tab to stamp a
     * self-explanatory audit-trail reason (e.g. "Automatic — arrears
     * reached 3x monthly bill, past payment deadline.") onto a bulk
     * disconnect started from that list, without forcing office staff to
     * type it themselves. Still fully editable before submit.
     */
    defaultNote?: string;
}) {
    const { data, setData, transform, post, processing, errors, reset } = useForm({
        customer_uuids: [] as string[],
        note: '',
        reason: 'tv_problem' as SuspendReason,
        include_fine: false,
    });

    // Re-seed the note whenever a fresh action opens (not on every
    // keystroke — `data.note` is deliberately excluded from the deps so
    // the office can still edit/clear it without this effect stomping the
    // change back).
    useEffect(() => {
        if (action) {
            setData('note', defaultNote ?? '');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [action]);

    // 2026-08 owner decision (business-rules.md section 6): the 2,000 FCFA
    // reconnection fine is admin discretion, opt-in via this checkbox,
    // unchecked by default — applies to every selected customer in the
    // batch if checked, regardless of `disconnected` vs `suspended`, never
    // required to submit.
    const canIncludeFine = action === 'reconnect';

    function close() {
        reset();
        onClose();
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        if (!action || customers.length === 0) {
            return;
        }

        const uuids = customers.map((customer) => customer.uuid);
        transform((formData) => ({ ...formData, customer_uuids: uuids }));

        post(`/disconnections/bulk-${action}`, {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    }

    const title =
        action === 'disconnect' ? 'Disconnect Selected Customers' : action === 'suspend' ? 'Suspend Selected Customers' : 'Reconnect Selected Customers';

    const previewNames = customers.slice(0, 4).map((customer) => customer.name);
    const remaining = customers.length - previewNames.length;

    return (
        <Modal open={action !== null} onClose={close} title={title}>
            {action && (
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                        <p className="font-medium text-slate-900">{customers.length} customer{customers.length === 1 ? '' : 's'} selected</p>
                        <p className="mt-1 text-slate-600">
                            {previewNames.join(', ')}
                            {remaining > 0 ? `, and ${remaining} more` : ''}
                        </p>
                    </div>

                    {errors.customer_uuids && <p className="text-xs text-red-600">{errors.customer_uuids}</p>}

                    {action === 'suspend' && (
                        <SelectInput
                            id="bulk-reason"
                            label="Reason (applies to all selected)"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value as SuspendReason)}
                            error={errors.reason}
                        >
                            {reasonOrder.map((key) => (
                                <option key={key} value={key}>
                                    {reasonLabels[key]}
                                </option>
                            ))}
                        </SelectInput>
                    )}

                    {canIncludeFine && (
                        <div>
                            <label className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={data.include_fine}
                                    onChange={(e) => setData('include_fine', e.target.checked)}
                                    className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                />
                                <span>
                                    Include reconnection fine — adds the 2,000 FCFA reconnection fine as a separate payment for every
                                    customer selected above. Optional; leave unchecked to reconnect without charging it.
                                </span>
                            </label>
                            {errors.include_fine && <p className="mt-1 text-xs text-red-600">{errors.include_fine}</p>}
                        </div>
                    )}

                    <div className="flex flex-col gap-1">
                        <label htmlFor="bulk-note" className="text-sm font-medium text-slate-700">
                            Note (applies to all selected){' '}
                            {action === 'suspend' && data.reason === 'other' && <span className="text-red-500">(required for &quot;Other&quot;)</span>}
                        </label>
                        <textarea
                            id="bulk-note"
                            rows={3}
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                            className={`rounded-lg border-0 px-3 py-2 text-base text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm ${
                                errors.note ? 'ring-red-400 focus:ring-red-500' : ''
                            }`}
                        />
                        {errors.note && <p className="text-xs text-red-600">{errors.note}</p>}
                    </div>

                    <div className="flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={close}
                            disabled={processing}
                            className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold sm:w-auto"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={action === 'disconnect' ? 'danger' : 'primary'}
                            disabled={processing || customers.length === 0}
                            className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold sm:w-auto"
                        >
                            {processing && <LoadingSpinner className="h-4 w-4" />}
                            {title}
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
