import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Modal } from '@/components/ui/Modal';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { DenominationCounter, denominationTotal, type DenominationCounts } from '@/components/cash/DenominationCounter';
import { paymentMethodLabel } from '@/components/shared/PaymentMethodFields';
import { formatCurrency } from '@/lib/formatCurrency';
import type { PaymentMethod, PaymentPurpose } from '@/types';
import { paymentPurposeLabel } from '@/lib/paymentPurpose';

interface Line {
    id: number;
    payment_uuid: string | null;
    customer_name: string | null;
    receipt_number: string | null;
    method: PaymentMethod;
    amount: string;
    reference: string | null;
    wallet_confirmed: boolean;
    /** Non-cash only: null = not seen yet, still owed by the collector. */
    outcome: 'found' | 'repaid' | 'written_off' | null;
    account_name: string | null;
    settled_by_name: string | null;
    settled_at: string | null;
    settled_note: string | null;
    purpose?: PaymentPurpose;
}

type LineOutcome = 'found' | 'repaid' | 'written_off';

interface Props {
    remittance: {
        uuid: string;
        number: string;
        status: 'submitted' | 'received';
        collector_name: string | null;
        business_date: string | null;
        expected: Record<PaymentMethod, string>;
        declared_cash: string;
        declared_denominations: Record<string, number> | null;
        collector_note: string | null;
        submitted_at: string | null;
        received_by_name: string | null;
        received_at: string | null;
        till_name: string | null;
        received_cash: string | null;
        cash_variance: string | null;
        receiver_note: string | null;
        shortage_status: 'open' | 'repaid' | 'written_off' | null;
        shortage_note: string | null;
        /** What is still owed after earlier extra cash covered part of it. */
        shortage_owed: string;
        /** Extra cash handed in here, kept as the collector's credit for later shortages. */
        credit_remaining: string | null;
        /** Cash taken at the desk, straight into the recorder's till. */
        at_desk: boolean;
        lines: Line[];
        /** Technician fees are counted with the cash but belong to the technician (D13). */
        purpose_totals?: Record<PaymentPurpose, string>;
        /** Bons the collector already paid out of this cash — paid by the cashier as part of receiving. */
        bons?: { uuid: string; number: string; payee_name: string; purpose: string; amount: string; status: string }[];
        bons_total?: string;
        cash_to_hand_over?: string;
    };
    can: { receive: boolean; repay_shortage: boolean; write_off_shortage: boolean; settle_line: Record<LineOutcome, boolean> };
    /** The branch's bank accounts, when a bank payment is still to be ticked. */
    bank_accounts: { uuid: string; name: string }[];
    denominations: number[];
}

/** Money that went straight into a company account — ticked off its statement. */
const isNonCash = (method: PaymentMethod) => method !== 'cash';

/**
 * One handover — App\Http\Controllers\RemittanceController::show
 * (docs/plans/cash-controls.md, wave 4). The cashier counts the cash
 * themselves (never trusting the declared figure), ticks every MoMo / Orange
 * / bank line they can see on the account's statement, and receives it into
 * their till. A line not ticked stays owed by the collector until it is
 * found later, paid in cash or written off (owner, 2026-10-28).
 */
export default function CashRemittance({ remittance, can, bank_accounts, denominations }: Props) {
    const [counts, setCounts] = useState<DenominationCounts>({});
    const [confirmed, setConfirmed] = useState<number[]>([]);
    const [shortageNote, setShortageNote] = useState('');
    const [settling, setSettling] = useState<{ line: Line; outcome: LineOutcome } | null>(null);
    const { data, setData, post, processing, errors, transform } = useForm({ received_cash: '', note: '', bank_account: '' });

    const counted = denominationTotal(counts);
    const usingCounter = counted > 0;
    const received = usingCounter ? counted : Number(data.received_cash || 0);
    const variance = received - Number(remittance.cash_to_hand_over ?? remittance.expected.cash);
    const nonCashLines = remittance.lines.filter((line) => isNonCash(line.method));
    const owedLines = nonCashLines.filter((line) => remittance.status === 'received' && line.outcome === null);
    const tickingBank = remittance.lines.some((line) => line.method === 'bank' && confirmed.includes(line.id));
    const canSettleAny = can.settle_line.found || can.settle_line.repaid || can.settle_line.written_off;

    transform((form) => ({
        ...form,
        received_cash: String(received),
        denominations: usingCounter ? counts : null,
        confirmed_lines: confirmed,
        bank_account: form.bank_account || null,
    }));

    return (
        <AppLayout title={remittance.number} breadcrumbs={[{ label: 'Cash book', href: '/cash' }, { label: remittance.number }]}>
            <Head title={remittance.number} />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl text-slate-900">{remittance.number}</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Handed over by <strong>{remittance.collector_name}</strong>
                        {remittance.submitted_at ? ` on ${new Date(remittance.submitted_at).toLocaleString()}` : ''}
                    </p>
                </div>
                <div className="flex gap-2">
                    {remittance.at_desk ? (
                        <Badge tone="blue">Taken at the desk</Badge>
                    ) : remittance.status === 'submitted' ? (
                        <Badge tone="yellow">Waiting for cashier</Badge>
                    ) : (
                        <Badge tone="green">Received</Badge>
                    )}
                    {remittance.credit_remaining !== null && Number(remittance.credit_remaining) > 0 && (
                        <Badge tone="yellow">{formatCurrency(remittance.credit_remaining)} extra — collector&apos;s credit</Badge>
                    )}
                    {remittance.shortage_status === 'open' && <Badge tone="red">Shortage open</Badge>}
                    {remittance.shortage_status === 'repaid' && <Badge tone="green">Shortage repaid</Badge>}
                    {remittance.shortage_status === 'written_off' && <Badge>Shortage written off</Badge>}
                </div>
            </div>

            <div className="mb-6 grid gap-4 sm:grid-cols-4">
                <Figure label="Cash collected (system)" value={remittance.expected.cash} />
                <Figure label={remittance.bons && remittance.bons.length > 0 ? 'Cash due after bons' : 'Cash declared by collector'} value={remittance.bons && remittance.bons.length > 0 ? (remittance.cash_to_hand_over ?? null) : remittance.declared_cash} />
                <Figure label="Cash received" value={remittance.received_cash} />
                <Figure
                    label="Difference"
                    value={remittance.cash_variance}
                    tone={remittance.cash_variance !== null && Number(remittance.cash_variance) < 0 ? 'red' : 'slate'}
                />
            </div>

            {remittance.bons && remittance.bons.length > 0 && (
                <Card className="mb-6">
                    <CardBody className="text-sm text-slate-700">
                        <strong>Bons already paid from these collections ({formatCurrency(remittance.bons_total ?? '0')}):</strong> receiving this handover pays them —
                        count only the remaining cash (declared: {formatCurrency(remittance.declared_cash)}).
                        <ul className="mt-1 list-disc pl-5">
                            {remittance.bons.map((bon) => (
                                <li key={bon.uuid}>
                                    <Link href={`/vouchers/${bon.uuid}`} className="font-medium text-blue-700 hover:underline">
                                        {bon.number}
                                    </Link>{' '}
                                    — {bon.payee_name}: {formatCurrency(bon.amount)} ({bon.status})
                                </li>
                            ))}
                        </ul>
                    </CardBody>
                </Card>
            )}

            {remittance.purpose_totals && Number(remittance.purpose_totals.technician_fee) > 0 && (
                <Card className="mb-6">
                    <CardBody className="text-sm text-slate-700">
                        <strong>Technician motivation fees in this handover:</strong> {formatCurrency(remittance.purpose_totals.technician_fee)} — count them
                        with the cash, then pay the technician from{' '}
                        <Link href="/cash/technician-fees" className="font-medium text-blue-700 hover:underline">
                            Technician fees
                        </Link>
                        . They are not company income.
                    </CardBody>
                </Card>
            )}

            {remittance.collector_note && (
                <Card className="mb-6">
                    <CardBody className="text-sm text-slate-700">
                        <strong>Collector&apos;s note:</strong> {remittance.collector_note}
                    </CardBody>
                </Card>
            )}

            <Card className="mb-6">
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">{remittance.lines.length} payment(s)</h3>
                </CardHeader>
                <Table label="Payments in this handover">
                    <TableHead>
                        <Th>Customer</Th>
                        <Th>Receipt</Th>
                        <Th>Paid by</Th>
                        <Th className="text-right">Amount</Th>
                        <Th className="text-right">On the statement?</Th>
                    </TableHead>
                    <TableBody>
                        {remittance.lines.map((line) => (
                            <tr key={line.id}>
                                <Td>
                                    {line.payment_uuid ? (
                                        <Link href={`/payments/${line.payment_uuid}`} className="font-medium text-slate-900 hover:underline">
                                            {line.customer_name ?? '—'}
                                        </Link>
                                    ) : (
                                        (line.customer_name ?? '—')
                                    )}
                                    {line.purpose && line.purpose !== 'subscription' && (
                                        <div>
                                            <Badge tone={line.purpose === 'technician_fee' ? 'yellow' : 'blue'}>{paymentPurposeLabel(line.purpose)}</Badge>
                                        </div>
                                    )}
                                </Td>
                                <Td className="font-mono text-xs">{line.receipt_number ?? '—'}</Td>
                                <Td>
                                    {paymentMethodLabel(line.method)}
                                    {line.reference && <div className="font-mono text-xs text-slate-500">{line.reference}</div>}
                                </Td>
                                <Td className="text-right font-medium">{formatCurrency(line.amount)}</Td>
                                <Td className="text-right">
                                    {!isNonCash(line.method) ? (
                                        <span className="text-xs text-slate-400">—</span>
                                    ) : remittance.status === 'received' ? (
                                        <LineStatus
                                            line={line}
                                            can={can.settle_line}
                                            onSettle={(outcome) => setSettling({ line, outcome })}
                                        />
                                    ) : can.receive ? (
                                        <label className="inline-flex items-center gap-1.5 text-xs text-slate-600">
                                            <input
                                                type="checkbox"
                                                checked={confirmed.includes(line.id)}
                                                onChange={(e) =>
                                                    setConfirmed((current) => (e.target.checked ? [...current, line.id] : current.filter((id) => id !== line.id)))
                                                }
                                                className="h-4 w-4 rounded border-slate-300 text-blue-600"
                                            />
                                            {line.method === 'bank' ? 'Seen in the bank' : 'Seen in wallet'}
                                        </label>
                                    ) : (
                                        <span className="text-xs text-slate-400">to check</span>
                                    )}
                                </Td>
                            </tr>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            {can.receive && (
                <Card className="mb-6">
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Count and receive</h3>
                    </CardHeader>
                    <CardBody>
                        <form
                            className="flex flex-col gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                post(`/cash/remittances/${remittance.uuid}/receive`, { preserveScroll: true });
                            }}
                        >
                            <p className="text-sm text-slate-500">Count the cash yourself — don&apos;t copy the collector&apos;s figure.</p>
                            <DenominationCounter denominations={denominations} counts={counts} onChange={setCounts} />
                            {!usingCounter && (
                                <TextInput
                                    id="received_cash"
                                    label="Or type the total cash counted (FCFA)"
                                    type="number"
                                    min="0"
                                    value={data.received_cash}
                                    onChange={(e) => setData('received_cash', e.target.value)}
                                    error={errors.received_cash}
                                />
                            )}
                            {usingCounter && errors.received_cash && <p className="text-xs text-red-600">{errors.received_cash}</p>}
                            <div className={`rounded-lg p-3 text-sm ${variance === 0 ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-800'}`}>
                                {variance === 0
                                    ? 'The count matches the cash the system expects.'
                                    : variance < 0
                                      ? `${formatCurrency(-variance)} short — any extra cash ${remittance.collector_name} handed in before covers it first; the rest stays open until repaid or written off.`
                                      : `${formatCurrency(variance)} more than expected — kept as ${remittance.collector_name}'s credit for a later shortage.`}
                                {nonCashLines.length > 0 && confirmed.length < nonCashLines.length && (
                                    <div className="mt-1 text-xs">
                                        {nonCashLines.length - confirmed.length} MoMo / bank payment(s) not ticked — {remittance.collector_name} will owe them until they
                                        are found, paid in cash or written off.
                                    </div>
                                )}
                            </div>
                            {tickingBank && bank_accounts.length > 1 && (
                                <SelectInput
                                    id="bank_account"
                                    label="Bank account the bank payments went into"
                                    value={data.bank_account}
                                    onChange={(e) => setData('bank_account', e.target.value)}
                                    error={errors.bank_account}
                                    required
                                >
                                    <option value="">Choose…</option>
                                    {bank_accounts.map((account) => (
                                        <option key={account.uuid} value={account.uuid}>
                                            {account.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            )}
                            {!(tickingBank && bank_accounts.length > 1) && errors.bank_account && <p className="text-xs text-red-600">{errors.bank_account}</p>}
                            <TextInput id="receive_note" label="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                            <Button type="submit" disabled={processing}>
                                Receive {formatCurrency(received)} into my till
                            </Button>
                        </form>
                    </CardBody>
                </Card>
            )}

            {remittance.status === 'received' && (
                <Card className="mb-6">
                    <CardBody className="text-sm text-slate-700">
                        Received by <strong>{remittance.received_by_name}</strong> into {remittance.till_name}
                        {remittance.received_at ? ` on ${new Date(remittance.received_at).toLocaleString()}` : ''}.
                        {remittance.receiver_note && <div className="mt-1">Note: {remittance.receiver_note}</div>}
                        {remittance.shortage_note && <div className="mt-1">Shortage: {remittance.shortage_note}</div>}
                    </CardBody>
                </Card>
            )}

            {owedLines.length > 0 && (
                <Card className="mb-6 border-red-200">
                    <CardBody className="text-sm text-red-800">
                        <strong>
                            {owedLines.length} MoMo / bank payment(s) not found —{' '}
                            {formatCurrency(owedLines.reduce((sum, line) => sum + Number(line.amount), 0))} still owed by {remittance.collector_name}.
                        </strong>{' '}
                        {canSettleAny
                            ? 'Check the statement again: mark each one found, paid in cash, or written off.'
                            : 'Someone else in the office settles them.'}
                    </CardBody>
                </Card>
            )}

            {settling && (
                <SettleLineModal
                    line={settling.line}
                    outcome={settling.outcome}
                    remittanceUuid={remittance.uuid}
                    bankAccounts={bank_accounts}
                    onClose={() => setSettling(null)}
                />
            )}

            {(can.repay_shortage || can.write_off_shortage) && (
                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">
                            Settle the {formatCurrency(remittance.shortage_owed)} shortage
                        </h3>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-3">
                        <TextInput id="shortage_note" label="Note" value={shortageNote} onChange={(e) => setShortageNote(e.target.value)} />
                        <div className="flex flex-wrap gap-2">
                            {can.repay_shortage && (
                                <Button
                                    onClick={() => router.post(`/cash/remittances/${remittance.uuid}/shortage`, { outcome: 'repaid', note: shortageNote }, { preserveScroll: true })}
                                >
                                    Collector paid it — receive into my till
                                </Button>
                            )}
                            {can.write_off_shortage && (
                                <Button
                                    variant="warning"
                                    onClick={() =>
                                        router.post(`/cash/remittances/${remittance.uuid}/shortage`, { outcome: 'written_off', note: shortageNote }, { preserveScroll: true })
                                    }
                                >
                                    Write it off
                                </Button>
                            )}
                        </div>
                    </CardBody>
                </Card>
            )}
        </AppLayout>
    );
}

const OUTCOME_LABELS: Record<LineOutcome, string> = { found: 'Found', repaid: 'Paid in cash', written_off: 'Written off' };

/** A received handover's MoMo / bank line: seen, settled later, or still owed with the ways to settle it. */
function LineStatus({ line, can, onSettle }: { line: Line; can: Record<LineOutcome, boolean>; onSettle: (outcome: LineOutcome) => void }) {
    if (line.outcome !== null) {
        return (
            <div className="flex flex-col items-end gap-0.5">
                <Badge tone={line.outcome === 'written_off' ? 'slate' : 'green'}>{OUTCOME_LABELS[line.outcome]}</Badge>
                {line.settled_by_name && (
                    <span className="text-xs text-slate-500">
                        {line.settled_by_name}
                        {line.settled_at ? `, ${new Date(line.settled_at).toLocaleDateString()}` : ''}
                    </span>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col items-end gap-1">
            <Badge tone="red">Not found — owed</Badge>
            <div className="flex flex-wrap justify-end gap-1">
                {(Object.keys(OUTCOME_LABELS) as LineOutcome[])
                    .filter((outcome) => can[outcome])
                    .map((outcome) => (
                        <button key={outcome} type="button" onClick={() => onSettle(outcome)} className="text-xs font-medium text-blue-700 hover:underline">
                            {outcome === 'found' ? 'Found it' : OUTCOME_LABELS[outcome]}
                        </button>
                    ))}
            </div>
        </div>
    );
}

/** RemittanceController::settleLine — one owed line, one way. */
function SettleLineModal({
    line,
    outcome,
    remittanceUuid,
    bankAccounts,
    onClose,
}: {
    line: Line;
    outcome: LineOutcome;
    remittanceUuid: string;
    bankAccounts: { uuid: string; name: string }[];
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({ outcome, note: '', bank_account: '' });
    const amount = formatCurrency(line.amount);
    const intro =
        outcome === 'found'
            ? `${amount} (${paymentMethodLabel(line.method)} ${line.reference ?? ''}) is on the statement after all — it is booked into the account.`
            : outcome === 'repaid'
              ? `The collector pays the ${amount} in cash — count it; it goes into your till.`
              : `The ${amount} is lost. Say why — this is kept with the handover.`;

    return (
        <Modal open onClose={onClose} title={`${OUTCOME_LABELS[outcome]} — ${line.customer_name ?? 'payment'}`}>
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/cash/remittances/${remittanceUuid}/lines/${line.id}/settle`, { preserveScroll: true, onSuccess: onClose });
                }}
            >
                <p className="text-sm text-slate-600">{intro}</p>
                {outcome === 'found' && line.method === 'bank' && bankAccounts.length > 1 && (
                    <SelectInput id="settle_bank_account" label="Bank account" value={data.bank_account} onChange={(e) => setData('bank_account', e.target.value)} error={errors.bank_account} required>
                        <option value="">Choose…</option>
                        {bankAccounts.map((account) => (
                            <option key={account.uuid} value={account.uuid}>
                                {account.name}
                            </option>
                        ))}
                    </SelectInput>
                )}
                {errors.bank_account && !(outcome === 'found' && line.method === 'bank' && bankAccounts.length > 1) && (
                    <p className="text-xs text-red-600">{errors.bank_account}</p>
                )}
                <TextInput
                    id="settle_note"
                    label={outcome === 'written_off' ? 'Reason' : 'Note (optional)'}
                    value={data.note}
                    onChange={(e) => setData('note', e.target.value)}
                    error={errors.note ?? errors.outcome}
                    required={outcome === 'written_off'}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant={outcome === 'written_off' ? 'warning' : 'primary'} disabled={processing}>
                        {OUTCOME_LABELS[outcome]}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function Figure({ label, value, tone = 'slate' }: { label: string; value: string | null; tone?: 'slate' | 'red' }) {
    return (
        <Card>
            <CardBody>
                <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
                <div className={`mt-1 text-xl font-semibold ${tone === 'red' ? 'text-red-700' : 'text-slate-900'}`}>{value !== null ? formatCurrency(value) : '—'}</div>
            </CardBody>
        </Card>
    );
}
