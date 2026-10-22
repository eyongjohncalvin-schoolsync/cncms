import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { TextInput } from '@/components/ui/TextInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { DenominationCounter, denominationTotal, type DenominationCounts } from '@/components/cash/DenominationCounter';
import { paymentMethodLabel } from '@/components/shared/PaymentMethodFields';
import { formatCurrency } from '@/lib/formatCurrency';
import type { PaymentMethod, PaymentPurpose } from '@/types';
import { paymentPurposeLabel } from '@/lib/paymentPurpose';

interface Props {
    payments: {
        uuid: string;
        customer_name: string | null;
        amount: string;
        method: PaymentMethod;
        method_reference: string | null;
        receipt_number: string | null;
        verification_status: string;
        recorded_at: string | null;
        purpose?: PaymentPurpose;
    }[];
    totals: Record<PaymentMethod, string>;
    /** What the money is for — technician fees are the technician's, handed over with the cash. */
    purpose_totals?: Record<PaymentPurpose, string>;
    oldest_at: string | null;
    history: {
        uuid: string;
        number: string;
        status: 'submitted' | 'received';
        expected_cash: string;
        declared_cash: string;
        received_cash: string | null;
        cash_variance: string | null;
        shortage_status: 'open' | 'repaid' | 'written_off' | null;
        submitted_at: string | null;
    }[];
    denominations: number[];
}

/**
 * The daily income form — App\Http\Controllers\RemittanceController::create
 * (docs/plans/cash-controls.md, wave 4). The system lists everything the
 * collector recorded that hasn't been handed over; the collector counts the
 * cash they hold and submits. The cashier then counts it again and receives
 * it — a difference is recorded against the collector.
 */
export default function CashHandOver({ payments, totals, purpose_totals, oldest_at, history, denominations }: Props) {
    const [counts, setCounts] = useState<DenominationCounts>({});
    const { data, setData, post, processing, errors, transform } = useForm({ declared_cash: '', note: '' });

    const counted = denominationTotal(counts);
    const usingCounter = counted > 0;
    const declared = usingCounter ? counted : Number(data.declared_cash || 0);
    const difference = declared - Number(totals.cash);
    const mobileTotal = Number(totals.mtn_momo) + Number(totals.orange_money);

    transform((form) => ({ ...form, declared_cash: String(declared), denominations: usingCounter ? counts : null }));

    return (
        <AppLayout title="Hand over cash" breadcrumbs={[{ label: 'Hand over cash' }]}>
            <Head title="Hand over cash" />

            <div className="mb-6">
                <h2 className="font-display text-2xl text-slate-900">Daily income — hand over cash</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-500">
                    Everything you recorded that hasn&apos;t been handed over yet. Count the cash you hold, then give it to the cashier — they count it again and
                    confirm.
                </p>
            </div>

            <div className="mb-6 grid gap-4 sm:grid-cols-4">
                <Total label="Cash to hand over" value={totals.cash} strong />
                <Total label="MTN MoMo" value={totals.mtn_momo} />
                <Total label="Orange Money" value={totals.orange_money} />
                <Total label="Bank" value={totals.bank} />
            </div>

            {purpose_totals && Number(purpose_totals.technician_fee) > 0 && (
                <p className="-mt-3 mb-6 text-sm text-slate-600">
                    Includes <span className="font-semibold">{formatCurrency(purpose_totals.technician_fee)}</span> of technician motivation fees — hand
                    them over with the cash; the cashier pays the technician.
                    {Number(purpose_totals.installation) > 0 && <> Installation fees: {formatCurrency(purpose_totals.installation)}.</>}
                </p>
            )}

            {payments.length === 0 ? (
                <Card className="mb-8">
                    <EmptyState title="Nothing to hand over" description="Every payment you recorded has already been handed over." />
                </Card>
            ) : (
                <div className="mb-8 grid gap-6 lg:grid-cols-[1fr_380px]">
                    <Card>
                        <CardHeader>
                            <h3 className="text-sm font-semibold text-slate-900">
                                {payments.length} collection(s){oldest_at ? ` since ${new Date(oldest_at).toLocaleString()}` : ''}
                            </h3>
                        </CardHeader>
                        <Table label="Collections to hand over">
                            <TableHead>
                                <Th>Customer</Th>
                                <Th>Receipt</Th>
                                <Th>Paid by</Th>
                                <Th className="text-right">Amount</Th>
                            </TableHead>
                            <TableBody>
                                {payments.map((payment) => (
                                    <tr key={payment.uuid}>
                                        <Td>
                                            <Link href={`/payments/${payment.uuid}`} className="font-medium text-slate-900 hover:underline">
                                                {payment.customer_name ?? '—'}
                                            </Link>
                                            <div className="text-xs text-slate-500">{payment.recorded_at ? new Date(payment.recorded_at).toLocaleString() : ''}</div>
                                            {payment.purpose && payment.purpose !== 'subscription' && (
                                                <Badge tone={payment.purpose === 'technician_fee' ? 'yellow' : 'blue'}>{paymentPurposeLabel(payment.purpose)}</Badge>
                                            )}
                                        </Td>
                                        <Td className="font-mono text-xs">{payment.receipt_number ?? '—'}</Td>
                                        <Td>
                                            {paymentMethodLabel(payment.method)}
                                            {payment.method_reference && <div className="text-xs text-slate-500">{payment.method_reference}</div>}
                                        </Td>
                                        <Td className="text-right font-medium">{formatCurrency(payment.amount)}</Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>

                    <Card>
                        <CardHeader>
                            <h3 className="text-sm font-semibold text-slate-900">Count the cash you hold</h3>
                        </CardHeader>
                        <CardBody>
                            <form
                                className="flex flex-col gap-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    post('/cash/remittances');
                                }}
                            >
                                <DenominationCounter denominations={denominations} counts={counts} onChange={setCounts} />
                                {!usingCounter && (
                                    <TextInput
                                        id="declared_cash"
                                        label="Or type the total cash (FCFA)"
                                        type="number"
                                        min="0"
                                        value={data.declared_cash}
                                        onChange={(e) => setData('declared_cash', e.target.value)}
                                        error={errors.declared_cash}
                                    />
                                )}
                                {usingCounter && errors.declared_cash && <p className="text-xs text-red-600">{errors.declared_cash}</p>}

                                <div className={`rounded-lg p-3 text-sm ${difference === 0 ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-800'}`}>
                                    {difference === 0
                                        ? 'Your cash matches what you recorded.'
                                        : difference < 0
                                          ? `You are ${formatCurrency(-difference)} short of the cash you recorded. Explain below.`
                                          : `You hold ${formatCurrency(difference)} more than you recorded. Explain below.`}
                                    {mobileTotal > 0 && (
                                        <div className="mt-1 text-xs">Mobile money ({formatCurrency(mobileTotal)}) is already in the company wallet — the cashier checks each transaction ID.</div>
                                    )}
                                </div>

                                <TextInput id="note" label="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} error={errors.note} />

                                <Button type="submit" disabled={processing}>
                                    Hand over {formatCurrency(declared)}
                                </Button>
                            </form>
                        </CardBody>
                    </Card>
                </div>
            )}

            <Card>
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">My recent handovers</h3>
                </CardHeader>
                {history.length === 0 ? (
                    <EmptyState title="No handovers yet" />
                ) : (
                    <Table label="My handovers">
                        <TableHead>
                            <Th>Number</Th>
                            <Th>Status</Th>
                            <Th className="text-right">Cash expected</Th>
                            <Th className="text-right">Received</Th>
                        </TableHead>
                        <TableBody>
                            {history.map((row) => (
                                <tr key={row.uuid}>
                                    <Td>
                                        <Link href={`/cash/remittances/${row.uuid}`} className="font-medium text-blue-700 hover:underline">
                                            {row.number}
                                        </Link>
                                        <div className="text-xs text-slate-500">{row.submitted_at ? new Date(row.submitted_at).toLocaleString() : ''}</div>
                                    </Td>
                                    <Td>
                                        {row.status === 'submitted' ? <Badge tone="yellow">Waiting for cashier</Badge> : <Badge tone="green">Received</Badge>}
                                        {row.shortage_status === 'open' && (
                                            <span className="ml-1">
                                                <Badge tone="red">Short</Badge>
                                            </span>
                                        )}
                                    </Td>
                                    <Td className="text-right">{formatCurrency(row.expected_cash)}</Td>
                                    <Td className="text-right">{row.received_cash !== null ? formatCurrency(row.received_cash) : '—'}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>
        </AppLayout>
    );
}

function Total({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <Card>
            <CardBody>
                <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
                <div className={`mt-1 text-xl font-semibold ${strong ? 'text-slate-900' : 'text-slate-700'}`}>{formatCurrency(value)}</div>
            </CardBody>
        </Card>
    );
}
