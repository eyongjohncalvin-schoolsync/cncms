import { Head, Link, router } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { paymentMethodLabel } from '@/components/shared/PaymentMethodFields';
import { formatCurrency } from '@/lib/formatCurrency';
import type { PaymentMethod } from '@/types';

interface ReversalRequest {
    uuid: string;
    reason: string;
    requested_by: string | null;
    requested_at: string | null;
    payment: {
        uuid: string;
        customer_name: string | null;
        amount: string;
        method: PaymentMethod;
        verification_status: string;
        collected_at: string | null;
    };
    can_decline: boolean;
}

/**
 * Collectors' requests for the office to reverse a payment —
 * App\Http\Controllers\PaymentReversalRequestController (owner, 2026-10-28).
 * Open the payment to reverse it (the Reverse dialog carries the collector's
 * reason), or decline with a note the collector sees.
 */
export default function PaymentsReversalRequests({ requests }: { requests: ReversalRequest[] }) {
    const decline = (request: ReversalRequest) => {
        const note = window.prompt('Why is the payment kept? The collector sees this.');

        if (note && note.trim().length >= 3) {
            router.post(`/reversal-requests/${request.uuid}/decline`, { note }, { preserveScroll: true });
        }
    };

    return (
        <AppLayout title="Reversal requests" breadcrumbs={[{ label: 'Payments', href: '/payments' }, { label: 'Reversal requests' }]}>
            <Head title="Reversal requests" />

            <div className="mb-6">
                <h2 className="font-display text-2xl text-slate-900">Reversal requests</h2>
                <p className="mt-1 text-sm text-slate-500">Collectors asking the office to undo a payment. Open it to reverse it, or decline with a reason.</p>
            </div>

            {requests.length === 0 ? (
                <EmptyState title="No requests waiting" description="When a collector asks for a payment to be reversed, it shows here." />
            ) : (
                <Card>
                    <Table label="Reversal requests">
                        <TableHead>
                            <Th>Asked by</Th>
                            <Th>Customer</Th>
                            <Th className="text-right">Amount</Th>
                            <Th>Reason</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {requests.map((request) => (
                                <tr key={request.uuid}>
                                    <Td>
                                        <div className="font-medium text-slate-900">{request.requested_by ?? '—'}</div>
                                        {request.requested_at && <div className="text-xs text-slate-500">{new Date(request.requested_at).toLocaleString()}</div>}
                                    </Td>
                                    <Td>
                                        {request.payment.customer_name ?? '—'}
                                        <div className="text-xs text-slate-500">
                                            {paymentMethodLabel(request.payment.method)} · {request.payment.verification_status}
                                        </div>
                                    </Td>
                                    <Td className="text-right font-medium">{formatCurrency(request.payment.amount)}</Td>
                                    <Td className="max-w-xs text-sm text-slate-700">{request.reason}</Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-2">
                                            <Link
                                                href={`/payments/${request.payment.uuid}`}
                                                className="inline-flex items-center rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700"
                                            >
                                                Open
                                            </Link>
                                            {request.can_decline && (
                                                <Button type="button" variant="secondary" onClick={() => decline(request)}>
                                                    Decline
                                                </Button>
                                            )}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </AppLayout>
    );
}
