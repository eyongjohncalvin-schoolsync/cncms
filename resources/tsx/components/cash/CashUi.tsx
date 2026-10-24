import { router, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import { IconMinus, IconPlus } from '@tabler/icons-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { MAX_RECEIPT_BYTES, ReceiptPicker, TextArea } from '@/components/resources/ExpenseUi';
import { formatCurrency } from '@/lib/formatCurrency';
import type { CashCloseStatus, CashHandover, CashHandoverStatus } from '@/types';

/**
 * Building blocks for the Cash desk page: labels, badges and the forms
 * behind "Hand over", "Confirm", "Count today" (blind) and "Deposit".
 */

export const ACCOUNT_TYPE_LABELS: Record<string, string> = {
    till: 'Till',
    safe: 'Safe',
    petty_cash: 'Petty cash',
    bank: 'Bank',
    mtn_momo: 'MTN MoMo',
    orange_money: 'Orange Money',
};

export function accountTypeLabel(type: string): string {
    return ACCOUNT_TYPE_LABELS[type] ?? type;
}

const HANDOVER_BADGE: Record<CashHandoverStatus, { label: string; tone: 'yellow' | 'green' | 'red' | 'slate' }> = {
    pending: { label: 'Waiting', tone: 'yellow' },
    confirmed: { label: 'Received', tone: 'green' },
    short: { label: 'Received short', tone: 'red' },
    cancelled: { label: 'Cancelled', tone: 'slate' },
};

export function HandoverStatusBadge({ status }: { status: string }) {
    const meta = HANDOVER_BADGE[status as CashHandoverStatus] ?? { label: status, tone: 'slate' as const };

    return <Badge tone={meta.tone}>{meta.label}</Badge>;
}

const CLOSE_BADGE: Record<CashCloseStatus, { label: string; tone: 'yellow' | 'green' | 'red' | 'blue' }> = {
    balanced: { label: 'Balanced', tone: 'green' },
    approved: { label: 'Settled', tone: 'green' },
    pending: { label: 'Waiting for manager', tone: 'yellow' },
    rejected: { label: 'Count again', tone: 'red' },
};

export function CloseStatusBadge({ status }: { status: string }) {
    const meta = CLOSE_BADGE[status as CashCloseStatus] ?? { label: status, tone: 'blue' as const };

    return <Badge tone={meta.tone}>{meta.label}</Badge>;
}

/** "+1,500 FCFA" / "−2,000 FCFA" with colour. */
export function Variance({ value }: { value: string | null }) {
    if (value === null) {
        return <span className="text-slate-400">—</span>;
    }

    const amount = Number(value);

    if (amount === 0) {
        return <span className="font-semibold text-green-700">0 FCFA</span>;
    }

    return (
        <span className={`font-semibold tabular-nums ${amount < 0 ? 'text-red-700' : 'text-amber-700'}`}>
            {amount < 0 ? '−' : '+'}
            {formatCurrency(Math.abs(amount))}
        </span>
    );
}

export function SubmitRow({ processing, label, onCancel, variant = 'primary' }: { processing: boolean; label: string; onCancel: () => void; variant?: 'primary' | 'danger' | 'warning' }) {
    return (
        <div className="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end">
            <Button type="button" variant="secondary" onClick={onCancel} className="min-h-[44px] w-full sm:min-h-0 sm:w-auto">
                Cancel
            </Button>
            <Button type="submit" variant={variant} disabled={processing} className="min-h-[48px] w-full font-semibold sm:min-h-0 sm:w-auto">
                {processing && <LoadingSpinner className="text-white" />}
                {label}
            </Button>
        </div>
    );
}

export interface HandoverTarget {
    uuid: string;
    type: string;
    name: string;
    holder: string | null;
}

/** Giver side: who to, how much. The server answers with a 6-digit code. */
export function HandoverForm({ targets, onDone }: { targets: HandoverTarget[]; onDone: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ to_account_uuid: '', amount: '', note: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/cash/handovers', { preserveScroll: true, onSuccess: () => onDone() });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <p className="text-sm text-slate-600">You'll get a 6-digit code. The receiver counts the cash and types the code to confirm. Until then the cash stays yours.</p>
            <SelectInput id="handover_to" label="Give to" required value={data.to_account_uuid} onChange={(e) => setData('to_account_uuid', e.target.value)} error={errors.to_account_uuid}>
                <option value="">Choose who receives it</option>
                {targets.map((target) => (
                    <option key={target.uuid} value={target.uuid}>
                        {target.name}
                        {target.holder ? ` — ${target.holder}` : ''} ({accountTypeLabel(target.type)})
                    </option>
                ))}
            </SelectInput>
            <TextInput
                id="handover_amount"
                label="Amount (FCFA)"
                type="number"
                inputMode="numeric"
                min="0"
                step="1"
                required
                value={data.amount}
                onChange={(e) => setData('amount', e.target.value)}
                error={errors.amount}
            />
            <TextInput id="handover_note" label="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} error={errors.note} />
            <SubmitRow processing={processing} label={data.amount ? `Hand over ${formatCurrency(data.amount)}` : 'Start handover'} onCancel={onDone} />
        </form>
    );
}

/** Receiver side: the giver's code and the amount actually counted. */
export function ConfirmHandoverForm({ handover }: { handover: CashHandover }) {
    const { data, setData, post, processing, errors } = useForm({ code: '', received_amount: String(Number(handover.amount)) });
    const isShort = Number(data.received_amount) > 0 && Number(data.received_amount) < Number(handover.amount);

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/cash/handovers/${handover.uuid}/confirm`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <TextInput
                    id={`code_${handover.uuid}`}
                    label="Code from the giver"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxLength={6}
                    required
                    placeholder="••••••"
                    value={data.code}
                    onChange={(e) => setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))}
                    error={errors.code}
                    className="font-mono text-xl tracking-[0.4em]"
                />
                <TextInput
                    id={`received_${handover.uuid}`}
                    label="Amount you counted (FCFA)"
                    type="number"
                    inputMode="numeric"
                    min="0"
                    step="1"
                    required
                    value={data.received_amount}
                    onChange={(e) => setData('received_amount', e.target.value)}
                    error={errors.received_amount}
                />
            </div>
            {isShort && (
                <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Less than {formatCurrency(handover.amount)}: only {formatCurrency(data.received_amount)} moves to you. The missing{' '}
                    {formatCurrency(Number(handover.amount) - Number(data.received_amount))} stays with the giver and the manager is told.
                </p>
            )}
            <Button type="submit" disabled={processing || data.code.length !== 6} className="min-h-[48px] w-full bg-green-600 font-semibold shadow-green-600/20 hover:bg-green-700 sm:w-auto sm:self-start">
                {processing && <LoadingSpinner className="text-white" />}
                Confirm I received {data.received_amount ? formatCurrency(data.received_amount) : 'the cash'}
            </Button>
        </form>
    );
}

/**
 * Blind count: the person enters how many of each note and coin they hold.
 * The expected balance is deliberately never shown here — only after the
 * count is submitted (via `last_close`).
 */
export function CountForm({ denominations, onDone }: { denominations: number[]; onDone: () => void }) {
    const [counts, setCounts] = useState<Record<string, string>>(() => Object.fromEntries(denominations.map((value) => [String(value), ''])));
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirming, setConfirming] = useState(false);

    const total = useMemo(
        () => denominations.reduce((sum, value) => sum + value * (Number(counts[String(value)]) || 0), 0),
        [counts, denominations],
    );

    function setCount(value: number, next: string) {
        const clean = next.replace(/\D/g, '').slice(0, 6);
        setCounts((current) => ({ ...current, [String(value)]: clean }));
    }

    function step(value: number, delta: number) {
        const current = Number(counts[String(value)]) || 0;
        setCount(value, String(Math.max(0, current + delta)));
    }

    function submit() {
        const payload = Object.fromEntries(denominations.map((value) => [String(value), Number(counts[String(value)]) || 0]));

        router.post(
            '/cash/closes',
            { denominations: payload, note },
            {
                preserveScroll: false,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onDone(),
                onError: (errs) => {
                    setErrors(errs);
                    setConfirming(false);
                },
            },
        );
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setConfirming(true);
    }

    return (
        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
                {denominations.map((value) => {
                    const count = counts[String(value)];
                    const subtotal = value * (Number(count) || 0);

                    return (
                        <li key={value} className="flex items-center gap-2 px-3 py-2">
                            <div className="w-20 shrink-0 sm:w-28">
                                <p className="font-semibold text-slate-900 tabular-nums">{new Intl.NumberFormat('en-US').format(value)}</p>
                                <p className="text-[11px] text-slate-400">{value >= 500 ? 'note' : 'coin'}</p>
                            </div>
                            <div className="flex flex-1 items-center justify-end gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => step(value, -1)}
                                    aria-label={`One less ${value}`}
                                    className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700 active:bg-slate-200"
                                >
                                    <IconMinus size={18} stroke={2} />
                                </button>
                                <input
                                    type="text"
                                    inputMode="numeric"
                                    aria-label={`Number of ${value}`}
                                    value={count}
                                    placeholder="0"
                                    onChange={(e) => setCount(value, e.target.value)}
                                    onFocus={(e) => e.target.select()}
                                    className="h-11 w-16 rounded-lg border-0 text-center text-lg font-semibold text-slate-900 ring-1 ring-inset ring-slate-300 tabular-nums placeholder:text-slate-300 focus:ring-2 focus:ring-blue-600"
                                />
                                <button
                                    type="button"
                                    onClick={() => step(value, 1)}
                                    aria-label={`One more ${value}`}
                                    className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700 active:bg-blue-100"
                                >
                                    <IconPlus size={18} stroke={2} />
                                </button>
                            </div>
                            <p className="hidden w-28 shrink-0 text-right text-sm text-slate-500 tabular-nums sm:block">{subtotal > 0 ? formatCurrency(subtotal) : ''}</p>
                        </li>
                    );
                })}
            </ul>

            {Object.keys(errors).length > 0 && (
                <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{errors.denominations ?? Object.values(errors)[0]}</p>
            )}

            <TextArea id="count_note" label="Note (optional)" value={note} onChange={(e) => setNote(e.target.value)} placeholder="Anything the manager should know" />

            <div className="sticky bottom-0 z-10 -mx-4 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:rounded-lg sm:border sm:px-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-xs text-slate-500">You counted</p>
                        <p className="font-display text-2xl font-semibold text-slate-900 tabular-nums">{formatCurrency(total)}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={onDone} className="min-h-[48px] flex-1 sm:min-h-0 sm:flex-none">
                            Cancel
                        </Button>
                        <Button type="submit" className="min-h-[48px] flex-[2] font-semibold sm:min-h-0 sm:flex-none">
                            Submit count
                        </Button>
                    </div>
                </div>
            </div>

            <Modal open={confirming} onClose={() => setConfirming(false)} title="Submit this count?">
                <p className="text-sm text-slate-600">
                    You counted <span className="font-semibold text-slate-900">{formatCurrency(total)}</span>. After submitting you'll see what the system expected. A
                    count can't be changed once it's in.
                </p>
                <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button type="button" variant="secondary" onClick={() => setConfirming(false)} className="min-h-[44px] w-full sm:min-h-0 sm:w-auto">
                        Count again
                    </Button>
                    <Button type="button" onClick={submit} disabled={processing} className="min-h-[48px] w-full font-semibold sm:min-h-0 sm:w-auto">
                        {processing && <LoadingSpinner className="text-white" />}
                        Yes, submit
                    </Button>
                </div>
            </Modal>
        </form>
    );
}

export interface DepositAccount {
    uuid: string;
    type: string;
    name: string;
}

/** Cash out of my till into the bank / MoMo, with the slip photo. */
export function DepositForm({ accounts, onDone }: { accounts: DepositAccount[]; onDone: () => void }) {
    const { data, setData, post, processing, errors, setError, clearErrors } = useForm<{
        to_account_uuid: string;
        amount: string;
        reference: string;
        slip: File | null;
    }>({ to_account_uuid: accounts.length === 1 ? accounts[0].uuid : '', amount: '', reference: '', slip: null });

    function chooseSlip(file: File | null) {
        clearErrors('slip');

        if (file && file.size > MAX_RECEIPT_BYTES) {
            setError('slip', 'The slip must be 6 MB or smaller.');

            return;
        }

        setData('slip', file);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/cash/deposits', { forceFormData: true, preserveScroll: true, onSuccess: () => onDone() });
    }

    if (accounts.length === 0) {
        return <p className="text-sm text-slate-600">No bank or MoMo account is set up yet. Ask a manager to add one.</p>;
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <SelectInput id="deposit_to" label="Deposited into" required value={data.to_account_uuid} onChange={(e) => setData('to_account_uuid', e.target.value)} error={errors.to_account_uuid}>
                <option value="">Choose the account</option>
                {accounts.map((account) => (
                    <option key={account.uuid} value={account.uuid}>
                        {account.name} ({accountTypeLabel(account.type)})
                    </option>
                ))}
            </SelectInput>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput
                    id="deposit_amount"
                    label="Amount (FCFA)"
                    type="number"
                    inputMode="numeric"
                    min="0"
                    step="1"
                    required
                    value={data.amount}
                    onChange={(e) => setData('amount', e.target.value)}
                    error={errors.amount}
                />
                <TextInput
                    id="deposit_reference"
                    label="Slip / transaction number"
                    required
                    value={data.reference}
                    onChange={(e) => setData('reference', e.target.value)}
                    error={errors.reference}
                />
            </div>
            <ReceiptPicker file={data.slip} onChange={chooseSlip} error={errors.slip} required label="Deposit slip photo" />
            <p className="text-xs text-slate-500">The cash leaves your till now. A second person confirms it against the bank statement.</p>
            <SubmitRow processing={processing} label="Record deposit" onCancel={onDone} />
        </form>
    );
}

/**
 * Approve / reject with a note (note required when rejecting). Used for
 * count differences ({approve}) and deposits ({confirm}).
 */
export function DecisionModal({
    open,
    onClose,
    title,
    url,
    field,
    approve,
    description,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    url: string;
    field: 'approve' | 'confirm';
    approve: boolean;
    description?: string;
}) {
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    function close() {
        setNote('');
        setError(undefined);
        onClose();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        router.post(
            url,
            { [field]: approve, note },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => close(),
                onError: (errors) => setError(errors.note ?? Object.values(errors)[0]),
            },
        );
    }

    return (
        <Modal open={open} onClose={close} title={title}>
            <form onSubmit={submit} className="flex flex-col gap-4">
                {description && <p className="text-sm text-slate-600">{description}</p>}
                <TextArea
                    id="decision_note"
                    label={approve ? 'Note (optional)' : 'Reason'}
                    required={!approve}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    error={error}
                />
                <SubmitRow
                    processing={processing}
                    label={approve ? (field === 'confirm' ? 'Confirm deposit' : 'Approve difference') : field === 'confirm' ? 'Reject deposit' : 'Send back'}
                    onCancel={close}
                    variant={approve ? 'primary' : 'danger'}
                />
            </form>
        </Modal>
    );
}
