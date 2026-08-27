import { FormEvent, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { DropdownItem } from '@/components/ui/Dropdown';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Customer, CustomerManuscriptSummary, SuspendReason } from '@/types';

export type StatusAction = 'disconnect' | 'suspend' | 'reconnect';

// Exported for reuse by BulkStatusModal (the Disconnections page's bulk
// equivalent of this per-row modal) — one shared reason vocabulary, not two
// copies that could drift apart.
export const reasonLabels: Record<SuspendReason, string> = {
    tv_problem: 'TV / signal problem',
    poor_service: 'Poor service quality (needs attention)',
    customer_request: 'Customer requested pause',
    zone_transfer: 'Moved out of zone',
    other: 'Other',
};

export const reasonOrder: SuspendReason[] = ['tv_problem', 'poor_service', 'customer_request', 'zone_transfer', 'other'];

// `manuscript` is optional and only carries the fields this component needs
// (`total_arrears`, `payment_expiration`) — CustomerDetail (Customers/Show.tsx)
// already supplies it via App\Http\Controllers\CustomerController::
// shapeCustomerDetail()'s `$customer->latestManuscript` lookup, with no
// backend change needed. Callers that only have a plain Customer row
// (Customers/Index.tsx, Disconnections/Index.tsx's status-board tab) simply
// omit it — the reconnect modal still works, it just can't show the current
// arrears figure or a live "balance remaining" calculation in that context;
// likewise the suspend/disconnect prepaid-preservation copy below
// (references/prepaid-pause-handling.md) only renders when `manuscript` is
// present, matching that same established limitation.
type CustomerForActions = Pick<Customer, 'uuid' | 'name' | 'status'> & {
    manuscript?: Pick<CustomerManuscriptSummary, 'total_arrears' | 'payment_expiration'> | null;
};

/**
 * True when $customer has a `payment_expiration` that hasn't passed yet —
 * prepaid-pause-handling.md's "active/unexpired prepaid window" gate for
 * whether the suspend modal's pause/continue choice is even relevant. A
 * lapsed-but-still-set date (relevant only to the disconnect note, which
 * doesn't require it be unexpired — section 4's "active OR lapsed-during-
 * freeze window" wording) does NOT count here.
 */
function hasActiveUnexpiredPrepaidWindow(paymentExpiration: string | null | undefined): boolean {
    return !!paymentExpiration && new Date(paymentExpiration).getTime() > Date.now();
}

/**
 * The fast disconnect/suspend/reconnect status actions — a purpose-built
 * alternative to the full customer-edit form, used from both
 * Customers/Show.tsx and Customers/Index.tsx. `variant="buttons"` renders
 * full pill buttons (Show's header); `variant="links"` renders plain text
 * links matching the Edit/Delete links already in Index's Actions column.
 *
 * A single CustomerStatusModal instance backs all three actions — which one
 * is being submitted is fixed by which trigger opened the modal (held in
 * `action` state) and never changes together with the form data in the same
 * click the way Payments/Index.tsx's VerifyModal approve/reject toggle did,
 * so no useForm().transform() is needed here to dodge that stale-closure
 * batching bug.
 *
 * `variant="menu"` renders the triggers as plain menu-item-styled buttons
 * (via ui/Dropdown's `DropdownItem`) with no dropdown wrapper of their own —
 * they're meant to compose INSIDE a parent `Dropdown`'s panel (see
 * Customers/Index.tsx's Actions column), not open a second nested dropdown.
 */
export function CustomerStatusActions({
    customer,
    variant = 'buttons',
}: {
    customer: CustomerForActions;
    variant?: 'buttons' | 'links' | 'menu';
}) {
    const [action, setAction] = useState<StatusAction | null>(null);

    const canDisconnectOrSuspend = customer.status === 'active' || customer.status === 'passive';
    const canReconnect = customer.status === 'disconnected' || customer.status === 'suspended';

    if (variant === 'menu') {
        return (
            <>
                {canDisconnectOrSuspend && (
                    <>
                        <DropdownItem onClick={() => setAction('suspend')} variant="warning">
                            Suspend
                        </DropdownItem>
                        <DropdownItem onClick={() => setAction('disconnect')} variant="danger">
                            Disconnect
                        </DropdownItem>
                    </>
                )}
                {canReconnect && (
                    <DropdownItem onClick={() => setAction('reconnect')} variant="success">
                        Reconnect
                    </DropdownItem>
                )}
                <CustomerStatusModal customer={customer} action={action} onClose={() => setAction(null)} />
            </>
        );
    }

    if (variant === 'links') {
        return (
            <>
                {canDisconnectOrSuspend && (
                    <>
                        <button
                            type="button"
                            onClick={() => setAction('suspend')}
                            className="rounded text-sm font-medium text-amber-700 transition-colors hover:text-amber-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
                        >
                            Suspend
                        </button>
                        <button
                            type="button"
                            onClick={() => setAction('disconnect')}
                            className="rounded text-sm font-medium text-red-600 transition-colors hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                        >
                            Disconnect
                        </button>
                    </>
                )}
                {canReconnect && (
                    <button
                        type="button"
                        onClick={() => setAction('reconnect')}
                        className="rounded text-sm font-medium text-green-700 transition-colors hover:text-green-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600"
                    >
                        Reconnect
                    </button>
                )}
                <CustomerStatusModal customer={customer} action={action} onClose={() => setAction(null)} />
            </>
        );
    }

    return (
        <>
            <div className="flex gap-2">
                {canDisconnectOrSuspend && (
                    <>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setAction('suspend')}
                            className="rounded-lg px-3 py-2 text-sm font-semibold"
                        >
                            Suspend
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => setAction('disconnect')}
                            className="rounded-lg px-3 py-2 text-sm font-semibold"
                        >
                            Disconnect
                        </Button>
                    </>
                )}
                {canReconnect && (
                    <Button type="button" onClick={() => setAction('reconnect')} className="rounded-lg px-3 py-2 text-sm font-semibold">
                        Reconnect
                    </Button>
                )}
            </div>
            <CustomerStatusModal customer={customer} action={action} onClose={() => setAction(null)} />
        </>
    );
}

function CustomerStatusModal({ customer, action, onClose }: { customer: CustomerForActions; action: StatusAction | null; onClose: () => void }) {
    const { data, setData, patch, processing, errors, reset } = useForm({
        note: '',
        reason: 'tv_problem' as SuspendReason,
        include_fine: false,
        arrears_payment: '',
        // prepaid-pause-handling.md section 5: "Pause the prepaid countdown"
        // is the pre-selected, Recommended default — see
        // App\Services\CustomerStatusService::suspend()'s own $pausePrepaid
        // default of true.
        pause_prepaid: true,
    });

    // prepaid-pause-handling.md section 5 — the suspend modal's pause/continue
    // choice, and the disconnect modal's informational note, both only apply
    // when the customer actually has prepaid time on the books right now.
    const paymentExpiration = customer.manuscript?.payment_expiration ?? null;
    const hasActivePrepaidWindow = hasActiveUnexpiredPrepaidWindow(paymentExpiration);
    const prepaidExpiresLabel = paymentExpiration ? new Date(paymentExpiration).toLocaleDateString() : null;
    const prepaidDaysRemaining = useMemo(() => {
        if (!paymentExpiration) {
            return null;
        }

        const ms = new Date(paymentExpiration).getTime() - Date.now();

        return ms > 0 ? Math.ceil(ms / (24 * 60 * 60 * 1000)) : null;
    }, [paymentExpiration]);

    // 2026-08 owner decision (business-rules.md section 6): the 2,000 FCFA
    // reconnection fine is admin discretion, opt-in via this checkbox,
    // unchecked by default — shown for a reconnect of EITHER a
    // `disconnected` or `suspended` customer, never required to submit.
    const canIncludeFine = action === 'reconnect';

    // The customer's outstanding arrears as of their latest manuscript row —
    // absent when this modal was opened from a page that doesn't carry that
    // figure (see CustomerForActions's doc comment above). Optional and
    // discretionary: the admin can record a partial, full, or no arrears
    // payment at all — this is display guidance, not an enforced constraint
    // (App\Services\CustomerStatusService::reconnectOne()'s doc comment).
    const currentArrears = action === 'reconnect' ? (customer.manuscript?.total_arrears ?? null) : null;

    const arrearsRemaining = useMemo(() => {
        if (currentArrears === null || data.arrears_payment === '') {
            return null;
        }

        const arrears = Number(currentArrears);
        const payment = Number(data.arrears_payment);

        if (!Number.isFinite(arrears) || !Number.isFinite(payment) || payment <= 0) {
            return null;
        }

        return Math.max(0, arrears - payment);
    }, [currentArrears, data.arrears_payment]);

    function close() {
        reset();
        onClose();
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        if (!action) {
            return;
        }

        patch(`/customers/${customer.uuid}/${action}`, {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    }

    const title = action === 'disconnect' ? 'Disconnect Customer' : action === 'suspend' ? 'Suspend Customer' : 'Reconnect Customer';

    return (
        <Modal open={action !== null} onClose={close} title={title}>
            {action && (
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <p className="text-sm text-slate-600">
                        {action === 'disconnect' && (
                            <>
                                Disconnect <span className="font-medium text-slate-900">{customer.name}</span> for non-payment.
                            </>
                        )}
                        {action === 'suspend' && (
                            <>
                                Temporarily suspend <span className="font-medium text-slate-900">{customer.name}</span>&apos;s service.
                            </>
                        )}
                        {action === 'reconnect' && (
                            <>
                                Move <span className="font-medium text-slate-900">{customer.name}</span> back to active.
                            </>
                        )}
                    </p>

                    {action === 'suspend' && (
                        <SelectInput
                            id="reason"
                            label="Reason"
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

                    {/*
                        prepaid-pause-handling.md section 5: the suspend-time
                        pause/continue choice — only shown when there's an
                        active, unexpired prepaid window to actually choose
                        between; otherwise suspend proceeds exactly as it did
                        before this feature, no extra step.
                    */}
                    {action === 'suspend' && hasActivePrepaidWindow && (
                        <div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <p className="text-sm text-slate-700">
                                This customer has prepaid service through{' '}
                                <span className="font-semibold text-slate-900">{prepaidExpiresLabel}</span>. Choose what happens to that
                                remaining time while suspended:
                            </p>

                            <label className="flex items-start gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700 has-[:checked]:border-blue-400 has-[:checked]:ring-1 has-[:checked]:ring-blue-400">
                                <input
                                    type="radio"
                                    name="pause_prepaid"
                                    checked={data.pause_prepaid}
                                    onChange={() => setData('pause_prepaid', true)}
                                    className="mt-0.5 h-4 w-4 border-slate-300 text-blue-600 focus:ring-blue-600"
                                />
                                <span>
                                    <span className="flex items-center gap-2 font-medium text-slate-900">
                                        Pause the prepaid countdown
                                        <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700">
                                            Recommended
                                        </span>
                                    </span>
                                    <span className="mt-0.5 block text-slate-500">
                                        Their remaining prepaid days are preserved and resume exactly where they left off once reconnected, no
                                        matter how long the suspension lasts.
                                    </span>
                                </span>
                            </label>

                            <label className="flex items-start gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700 has-[:checked]:border-blue-400 has-[:checked]:ring-1 has-[:checked]:ring-blue-400">
                                <input
                                    type="radio"
                                    name="pause_prepaid"
                                    checked={!data.pause_prepaid}
                                    onChange={() => setData('pause_prepaid', false)}
                                    className="mt-0.5 h-4 w-4 border-slate-300 text-blue-600 focus:ring-blue-600"
                                />
                                <span>
                                    <span className="font-medium text-slate-900">Let it continue as normal</span>
                                    <span className="mt-0.5 block text-slate-500">
                                        The prepaid window keeps counting down during the suspension. If it runs out while still suspended,
                                        normal monthly billing will begin the moment they&apos;re reconnected.
                                    </span>
                                </span>
                            </label>
                        </div>
                    )}

                    {/*
                        prepaid-pause-handling.md section 5: disconnect gets a
                        purely informational note, never a choice — the
                        extension always happens on reconnect regardless.
                    */}
                    {action === 'disconnect' && hasActivePrepaidWindow && (
                        <p className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            This customer has <span className="font-semibold text-slate-900">{prepaidDaysRemaining}</span> day
                            {prepaidDaysRemaining === 1 ? '' : 's'} of prepaid service remaining — it will be preserved and resumed
                            automatically once reconnected.
                        </p>
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
                                    Include reconnection fine — adds the 2,000 FCFA reconnection fine as a separate payment. Optional; leave
                                    unchecked to reconnect without charging it.
                                </span>
                            </label>
                            {errors.include_fine && <p className="mt-1 text-xs text-red-600">{errors.include_fine}</p>}
                        </div>
                    )}

                    {action === 'reconnect' && (
                        <div className="flex flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            {currentArrears !== null && (
                                <p className="text-sm text-slate-700">
                                    Current outstanding arrears:{' '}
                                    <span className="font-semibold text-slate-900">{formatCurrency(currentArrears)}</span>
                                </p>
                            )}
                            <TextInput
                                id="arrears_payment"
                                label="Arrears payment received (FCFA) — optional"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0"
                                value={data.arrears_payment}
                                onChange={(e) => setData('arrears_payment', e.target.value)}
                                error={errors.arrears_payment}
                                className="bg-white"
                            />
                            <p className="text-xs text-slate-500">
                                Leave blank to reconnect without recording an arrears payment right now — a partial payment is fine too.
                            </p>
                            {arrearsRemaining !== null && (
                                <p className="text-sm text-slate-700">
                                    Balance remaining after this reconnection:{' '}
                                    <span className="font-semibold text-slate-900">
                                        {arrearsRemaining === 0 ? 'Fully paid' : formatCurrency(String(arrearsRemaining))}
                                    </span>
                                </p>
                            )}
                        </div>
                    )}

                    <div className="flex flex-col gap-1">
                        <label htmlFor="note" className="text-sm font-medium text-slate-700">
                            Note{' '}
                            {action === 'suspend' && data.reason === 'other' && <span className="text-red-500">(required for &quot;Other&quot;)</span>}
                        </label>
                        <textarea
                            id="note"
                            rows={3}
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                            className={`rounded-lg border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 ${
                                errors.note ? 'ring-red-400 focus:ring-red-500' : ''
                            }`}
                        />
                        {errors.note && <p className="text-xs text-red-600">{errors.note}</p>}
                    </div>

                    <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={close}
                            disabled={processing}
                            className="rounded-lg px-4 py-2.5 text-sm font-semibold"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={action === 'disconnect' ? 'danger' : 'primary'}
                            disabled={processing}
                            className="rounded-lg px-4 py-2.5 text-sm font-semibold"
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
