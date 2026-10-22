import { useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { TextInput } from '@/components/ui/TextInput';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { formatCurrency } from '@/lib/formatCurrency';

interface Fee {
    uuid: string;
    customer_name: string | null;
    amount: string;
    collected_by_name: string | null;
    remittance_number: string | null;
    recorded_at: string | null;
}

interface Payout {
    uuid: string;
    number: string;
    technician_name: string;
    amount: string;
    fees_count: number;
    paid_by_name: string | null;
    paid_at: string | null;
    note: string | null;
}

interface Props {
    fees: Fee[];
    payouts: Payout[];
    can_pay: boolean;
}

/**
 * Technician motivation fees — App\Http\Controllers\TechnicianFeeController
 * (docs/plans/installation-and-promotions.md, D13). The reconnection fine is
 * the technician's money: the collector hands it to the cashier with the day's
 * cash, and the cashier pays it out here from their till. Never company income.
 */
export default function CashTechnicianFees({ fees, payouts, can_pay }: Props) {
    const [selected, setSelected] = useState<string[]>([]);
    const { data, setData, post, processing, errors, transform, reset } = useForm({ technician_name: '', note: '' });

    const total = useMemo(
        () => fees.filter((fee) => selected.includes(fee.uuid)).reduce((sum, fee) => sum + Number(fee.amount), 0),
        [fees, selected],
    );

    transform((form) => ({ ...form, payments: selected }));

    const toggle = (uuid: string) => setSelected((current) => (current.includes(uuid) ? current.filter((value) => value !== uuid) : [...current, uuid]));

    return (
        <AppLayout title="Technician fees" breadcrumbs={[{ label: 'Cash book', href: '/cash' }, { label: 'Technician fees' }]}>
            <Head title="Technician fees" />

            <div className="mb-6">
                <h2 className="font-display text-2xl text-slate-900">Technician motivation fees</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-500">
                    Reconnection fines collected and received at the till. They belong to the technician who did the reconnection — pay them out here. They
                    are never company income.
                </p>
            </div>

            <div className="mb-8 grid gap-6 lg:grid-cols-[1fr_340px]">
                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Waiting to be paid to a technician</h3>
                    </CardHeader>
                    {fees.length === 0 ? (
                        <div className="p-4">
                            <EmptyState title="No fees waiting" description="Fees appear here once their handover has been received by a cashier." />
                        </div>
                    ) : (
                        <Table label="Technician fees waiting">
                            <TableHead>
                                {can_pay && <Th>Pay</Th>}
                                <Th>Customer</Th>
                                <Th>Collected by</Th>
                                <Th>Handover</Th>
                                <Th className="text-right">Amount</Th>
                            </TableHead>
                            <TableBody>
                                {fees.map((fee) => (
                                    <tr key={fee.uuid}>
                                        {can_pay && (
                                            <Td>
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Pay fee for ${fee.customer_name ?? 'customer'}`}
                                                    checked={selected.includes(fee.uuid)}
                                                    onChange={() => toggle(fee.uuid)}
                                                />
                                            </Td>
                                        )}
                                        <Td>
                                            <Link href={`/payments/${fee.uuid}`} className="font-medium text-slate-900 hover:underline">
                                                {fee.customer_name ?? '—'}
                                            </Link>
                                            <div className="text-xs text-slate-500">{fee.recorded_at ? new Date(fee.recorded_at).toLocaleString() : ''}</div>
                                        </Td>
                                        <Td>{fee.collected_by_name ?? '—'}</Td>
                                        <Td className="font-mono text-xs">{fee.remittance_number ?? '—'}</Td>
                                        <Td className="text-right font-medium">{formatCurrency(fee.amount)}</Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Card>

                {can_pay && (
                    <Card>
                        <CardHeader>
                            <h3 className="text-sm font-semibold text-slate-900">Pay the technician</h3>
                        </CardHeader>
                        <CardBody>
                            <form
                                className="flex flex-col gap-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    post('/cash/technician-fees/payouts', {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            reset();
                                            setSelected([]);
                                        },
                                    });
                                }}
                            >
                                <p className="text-sm text-slate-600">
                                    {selected.length} fee(s) selected · <span className="font-semibold text-slate-900">{formatCurrency(total)}</span> paid from your
                                    till.
                                </p>
                                <TextInput
                                    id="technician-name"
                                    label="Technician"
                                    required
                                    value={data.technician_name}
                                    onChange={(event) => setData('technician_name', event.target.value)}
                                    error={errors.technician_name}
                                />
                                <TextInput id="payout-note" label="Note" value={data.note} onChange={(event) => setData('note', event.target.value)} error={errors.note} />
                                {(errors as Record<string, string>).payments && <p className="text-xs text-red-600">{(errors as Record<string, string>).payments}</p>}
                                <Button type="submit" disabled={processing || selected.length === 0}>
                                    Record payout
                                </Button>
                            </form>
                        </CardBody>
                    </Card>
                )}
            </div>

            <Card>
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">Recent payouts</h3>
                </CardHeader>
                {payouts.length === 0 ? (
                    <div className="p-4">
                        <EmptyState title="No payouts yet" />
                    </div>
                ) : (
                    <Table label="Technician fee payouts">
                        <TableHead>
                            <Th>Number</Th>
                            <Th>Technician</Th>
                            <Th>Fees</Th>
                            <Th>Paid by</Th>
                            <Th className="text-right">Amount</Th>
                        </TableHead>
                        <TableBody>
                            {payouts.map((payout) => (
                                <tr key={payout.uuid}>
                                    <Td className="font-mono text-xs">{payout.number}</Td>
                                    <Td>
                                        {payout.technician_name}
                                        {payout.note && <div className="text-xs text-slate-500">{payout.note}</div>}
                                    </Td>
                                    <Td>{payout.fees_count}</Td>
                                    <Td>
                                        {payout.paid_by_name ?? '—'}
                                        <div className="text-xs text-slate-500">{payout.paid_at ? new Date(payout.paid_at).toLocaleString() : ''}</div>
                                    </Td>
                                    <Td className="text-right font-medium">{formatCurrency(payout.amount)}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>
        </AppLayout>
    );
}
