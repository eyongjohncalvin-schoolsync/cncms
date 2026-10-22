import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { TextInput } from '@/components/ui/TextInput';
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
    purpose?: PaymentPurpose;
}

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
        lines: Line[];
        /** Technician fees are counted with the cash but belong to the technician (D13). */
        purpose_totals?: Record<PaymentPurpose, string>;
    };
    can: { receive: boolean; repay_shortage: boolean; write_off_shortage: boolean };
    denominations: number[];
}

const isWallet = (method: PaymentMethod) => method === 'mtn_momo' || method === 'orange_money';

/**
 * One handover — App\Http\Controllers\RemittanceController::show
 * (docs/plans/cash-controls.md, wave 4). The cashier counts the cash
 * themselves (never trusting the declared figure), ticks every mobile-money
 * line they can see in the company wallet, and receives it into their till.
 */
export default function CashRemittance({ remittance, can, denominations }: Props) {
    const [counts, setCounts] = useState<DenominationCounts>({});
    const [confirmed, setConfirmed] = useState<number[]>([]);
    const [shortageNote, setShortageNote] = useState('');
    const { data, setData, post, processing, errors, transform } = useForm({ received_cash: '', note: '' });

    const counted = denominationTotal(counts);
    const usingCounter = counted > 0;
    const received = usingCounter ? counted : Number(data.received_cash || 0);
    const variance = received - Number(remittance.expected.cash);
    const walletLines = remittance.lines.filter((line) => isWallet(line.method));

    transform((form) => ({
        ...form,
        received_cash: String(received),
        denominations: usingCounter ? counts : null,
        confirmed_lines: confirmed,
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
                    {remittance.status === 'submitted' ? <Badge tone="yellow">Waiting for cashier</Badge> : <Badge tone="green">Received</Badge>}
                    {remittance.shortage_status === 'open' && <Badge tone="red">Shortage open</Badge>}
                    {remittance.shortage_status === 'repaid' && <Badge tone="green">Shortage repaid</Badge>}
                    {remittance.shortage_status === 'written_off' && <Badge>Shortage written off</Badge>}
                </div>
            </div>

            <div className="mb-6 grid gap-4 sm:grid-cols-4">
                <Figure label="Cash collected (system)" value={remittance.expected.cash} />
                <Figure label="Cash declared by collector" value={remittance.declared_cash} />
                <Figure label="Cash received" value={remittance.received_cash} />
                <Figure
                    label="Difference"
                    value={remittance.cash_variance}
                    tone={remittance.cash_variance !== null && Number(remittance.cash_variance) < 0 ? 'red' : 'slate'}
                />
            </div>

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
                        <Th className="text-right">In wallet?</Th>
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
                                    {!isWallet(line.method) ? (
                                        <span className="text-xs text-slate-400">—</span>
                                    ) : remittance.status === 'received' ? (
                                        line.wallet_confirmed ? <Badge tone="green">Seen</Badge> : <Badge tone="red">Not found</Badge>
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
                                            Seen in wallet
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
                                      ? `${formatCurrency(-variance)} short — it will stay open on ${remittance.collector_name} until repaid or written off.`
                                      : `${formatCurrency(variance)} more than expected — it will be recorded.`}
                                {walletLines.length > 0 && confirmed.length < walletLines.length && (
                                    <div className="mt-1 text-xs">
                                        {walletLines.length - confirmed.length} mobile-money payment(s) not ticked — they will be flagged as not found in the wallet.
                                    </div>
                                )}
                            </div>
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

            {(can.repay_shortage || can.write_off_shortage) && (
                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">
                            Settle the {formatCurrency(-Number(remittance.cash_variance ?? 0))} shortage
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
