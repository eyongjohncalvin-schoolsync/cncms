import { Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatusBadge, VerificationBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import type { CustomerDetail } from '@/types';

export default function CustomersShow({ customer }: { customer: CustomerDetail }) {
    return (
        <AppLayout title={customer.name}>
            <Head title={customer.name} />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <h2 className="text-lg font-semibold text-slate-900">{customer.name}</h2>
                    <StatusBadge status={customer.status} />
                </div>
                <div className="flex gap-2">
                    <a
                        href={`/customers/${customer.uuid}/bill/print`}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center justify-center gap-1.5 rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
                    >
                        Print Bill
                    </a>
                    <Link
                        href={`/customers/${customer.uuid}/edit`}
                        className="inline-flex items-center justify-center gap-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    >
                        Edit
                    </Link>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-1">
                    <CardHeader>Profile</CardHeader>
                    <CardBody className="flex flex-col gap-3 text-sm">
                        <Field label="Phone" value={customer.phone ?? '—'} />
                        <Field label="Zone" value={customer.zone_name} />
                        <Field label="Location" value={customer.location ?? '—'} />
                        <Field label="Level" value={customer.level} />
                        <Field label="Monthly Bill" value={formatCurrency(customer.bill)} />
                        <Field label="Description" value={customer.description ?? '—'} />
                    </CardBody>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader>Latest Manuscript</CardHeader>
                    <CardBody>
                        {customer.manuscript ? (
                            <div className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                                <Field label="Period" value={customer.manuscript.period} />
                                <Field label="Bill" value={formatCurrency(customer.manuscript.bill)} />
                                <Field label="Arrears" value={formatCurrency(customer.manuscript.total_arrears)} />
                                <Field label="Credit" value={formatCurrency(customer.manuscript.credit)} />
                                <Field label="Total Bill" value={formatCurrency(customer.manuscript.total_bill)} />
                                <Field label="Expires" value={customer.manuscript.payment_expiration ?? '—'} />
                            </div>
                        ) : (
                            <p className="text-sm text-slate-500">No manuscript calculated yet.</p>
                        )}
                    </CardBody>
                </Card>
            </div>

            <div className="mt-4">
                <h3 className="mb-2 text-sm font-semibold text-slate-700">Recent Payments</h3>
                {customer.recent_payments.length === 0 ? (
                    <EmptyState title="No payments recorded" />
                ) : (
                    <Table>
                        <TableHead>
                            <Th>Date</Th>
                            <Th>Amount</Th>
                            <Th>Credit</Th>
                            <Th>Frequency</Th>
                            <Th>Verification</Th>
                        </TableHead>
                        <TableBody>
                            {customer.recent_payments.map((payment) => (
                                <tr key={payment.uuid}>
                                    <Td>{new Date(payment.created_at).toLocaleDateString()}</Td>
                                    <Td>{formatCurrency(payment.amount)}</Td>
                                    <Td>{formatCurrency(payment.credit)}</Td>
                                    <Td className="capitalize">{payment.frequency}</Td>
                                    <Td>
                                        <VerificationBadge status={payment.verification_status} />
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>
        </AppLayout>
    );
}

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
            <p className="text-slate-800">{value}</p>
        </div>
    );
}
