import { router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { IconTool } from '@tabler/icons-react';
import { Card, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatCurrency } from '@/lib/formatCurrency';
import type { CustomerCharge, CustomerChargeStatus, CustomerInstallation, PaymentMethod } from '@/types';

/**
 * Installation fees on the customer page (docs/plans/installation-and-promotions.md,
 * phase 1). A separate balance: shown on the bill as its own line but never part
 * of the monthly total or arrears, and never a reason to disconnect (D1). Actions:
 * add a fee, record an instalment (D2), ask for the rest to be waived, and — for
 * someone other than whoever asked — approve or reject that waiver (D8).
 */
const STATUS: Record<CustomerChargeStatus, { label: string; tone: 'slate' | 'green' | 'yellow' | 'red' | 'blue' }> = {
    open: { label: 'Open', tone: 'yellow' },
    paid: { label: 'Paid', tone: 'green' },
    waived: { label: 'Waived', tone: 'blue' },
    refunded: { label: 'Refunded', tone: 'slate' },
};

const METHODS: { value: PaymentMethod; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'mtn_momo', label: 'MTN MoMo' },
    { value: 'orange_money', label: 'Orange Money' },
    { value: 'bank', label: 'Bank' },
];

type Dialog = { kind: 'create' } | { kind: 'pay'; charge: CustomerCharge } | { kind: 'waive'; charge: CustomerCharge } | null;

export function InstallationCard({ customerUuid, installation }: { customerUuid: string; installation: CustomerInstallation }) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const close = () => setDialog(null);

    return (
        <Card className="p-0">
            <CardHeader className="border-b border-slate-100">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-orange-50 text-orange-600">
                            <IconTool size={18} stroke={1.75} />
                        </span>
                        <div>
                            <h3 className="text-base font-semibold text-slate-900">Installation</h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Balance still owed: <span className="font-semibold text-slate-800">{formatCurrency(installation.balance)}</span> — separate from
                                the monthly bill, never counted as arrears
                            </p>
                        </div>
                    </div>
                    {installation.can_create && !installation.charges.some((charge) => charge.status === 'open') && (
                        <Button type="button" variant="secondary" onClick={() => setDialog({ kind: 'create' })}>
                            Add installation fee
                        </Button>
                    )}
                </div>
            </CardHeader>

            {installation.charges.length === 0 ? (
                <div className="p-4">
                    <EmptyState title="No installation fee on file" />
                </div>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {installation.charges.map((charge) => (
                        <ChargeRow key={charge.uuid} charge={charge} onPay={() => setDialog({ kind: 'pay', charge })} onWaive={() => setDialog({ kind: 'waive', charge })} />
                    ))}
                </ul>
            )}

            <CreateChargeModal open={dialog?.kind === 'create'} onClose={close} customerUuid={customerUuid} />
            <PayModal charge={dialog?.kind === 'pay' ? dialog.charge : null} onClose={close} />
            <WaiverModal charge={dialog?.kind === 'waive' ? dialog.charge : null} onClose={close} />
        </Card>
    );
}

function ChargeRow({ charge, onPay, onWaive }: { charge: CustomerCharge; onPay: () => void; onWaive: () => void }) {
    const decide = (decision: 'approve' | 'reject') => {
        const reason = decision === 'reject' ? window.prompt('Why is this waiver rejected? (optional)') : null;
        if (decision === 'reject' && reason === null) return;

        router.post(`/installation-charges/${charge.uuid}/waiver/${decision}`, decision === 'reject' ? { reason } : {}, { preserveScroll: true });
    };

    return (
        <li className="flex flex-col gap-3 px-5 py-4 text-sm sm:flex-row sm:items-start sm:justify-between">
            <div className="flex flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold text-slate-900">Fee {formatCurrency(charge.amount)}</span>
                    <Badge tone={STATUS[charge.status].tone}>{STATUS[charge.status].label}</Badge>
                    {charge.waiver_status === 'pending' && <Badge tone="yellow">Waiver awaiting approval</Badge>}
                </div>
                <p className="text-slate-600">
                    Paid {formatCurrency(charge.paid)} · Balance {formatCurrency(charge.balance)}
                    {charge.waived_amount !== null && ` · Waived ${formatCurrency(charge.waived_amount)}`}
                </p>
                <p className="text-xs text-slate-500">
                    {charge.requested_by_name ? `Added by ${charge.requested_by_name}` : 'Added'}
                    {charge.created_at ? ` on ${new Date(charge.created_at).toLocaleDateString()}` : ''}
                    {charge.reason ? ` — ${charge.reason}` : ''}
                </p>
                {charge.waiver_status && (
                    <p className="text-xs text-slate-500">
                        Waiver {charge.waiver_status}
                        {charge.waiver_requested_by_name ? ` (asked by ${charge.waiver_requested_by_name}` : ''}
                        {charge.waiver_decided_by_name ? `, decided by ${charge.waiver_decided_by_name})` : charge.waiver_requested_by_name ? ')' : ''}
                        {charge.waiver_reason ? ` — ${charge.waiver_reason}` : ''}
                        {charge.waiver_rejection_reason ? ` · Rejected: ${charge.waiver_rejection_reason}` : ''}
                    </p>
                )}
            </div>
            <div className="flex shrink-0 flex-wrap gap-2">
                {charge.can.record_payment && Number(charge.recordable) > 0 && (
                    <Button type="button" onClick={onPay}>
                        Record payment
                    </Button>
                )}
                {charge.can.request_waiver && (
                    <Button type="button" variant="secondary" onClick={onWaive}>
                        Request waiver
                    </Button>
                )}
                {charge.can.decide_waiver && (
                    <>
                        <Button type="button" variant="warning" onClick={() => decide('approve')}>
                            Approve waiver
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => decide('reject')}>
                            Reject
                        </Button>
                    </>
                )}
            </div>
        </li>
    );
}

function CreateChargeModal({ open, onClose, customerUuid }: { open: boolean; onClose: () => void; customerUuid: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({ amount: '', reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/customers/${customerUuid}/installation-charges`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Modal open={open} onClose={onClose} title="Add installation fee">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">The fee is owed separately from the monthly bill and may be paid in instalments.</p>
                <TextInput id="charge-amount" label="Fee (FCFA)" type="number" min="1" step="0.01" required value={data.amount} onChange={(e) => setData('amount', e.target.value)} error={errors.amount} />
                <TextInput id="charge-reason" label="Note" value={data.reason} onChange={(e) => setData('reason', e.target.value)} error={errors.reason} />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Add fee
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function PayModal({ charge, onClose }: { charge: CustomerCharge | null; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm<{ amount: string; method: PaymentMethod; method_reference: string }>({
        amount: '',
        method: 'cash',
        method_reference: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!charge) return;

        post(`/installation-charges/${charge.uuid}/payments`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Modal open={charge !== null} onClose={onClose} title="Record installation payment">
            {charge && (
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <p className="text-sm text-slate-600">
                        Up to {formatCurrency(charge.recordable)} can still be recorded. This money pays the installation fee only — it never becomes
                        subscription credit.
                    </p>
                    <TextInput id="pay-amount" label="Amount (FCFA)" type="number" min="1" step="0.01" max={charge.recordable} required value={data.amount} onChange={(e) => setData('amount', e.target.value)} error={errors.amount} />
                    <SelectInput id="pay-method" label="Paid by" value={data.method} onChange={(e) => setData('method', e.target.value as PaymentMethod)} error={errors.method}>
                        {METHODS.map((method) => (
                            <option key={method.value} value={method.value}>
                                {method.label}
                            </option>
                        ))}
                    </SelectInput>
                    {data.method !== 'cash' && (
                        <TextInput id="pay-reference" label="Transaction ID" value={data.method_reference} onChange={(e) => setData('method_reference', e.target.value)} error={errors.method_reference} />
                    )}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Record payment
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

function WaiverModal({ charge, onClose }: { charge: CustomerCharge | null; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!charge) return;

        post(`/installation-charges/${charge.uuid}/waiver`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Modal open={charge !== null} onClose={onClose} title="Request installation waiver">
            {charge && (
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <p className="text-sm text-slate-600">
                        Asks for the remaining {formatCurrency(charge.balance)} to be waived. Someone else with approval rights must confirm it.
                    </p>
                    <TextInput id="waiver-reason" label="Reason" required value={data.reason} onChange={(e) => setData('reason', e.target.value)} error={errors.reason} />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Request waiver
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
