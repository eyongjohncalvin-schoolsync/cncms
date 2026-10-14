import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { IconBrandWhatsapp, IconMail, IconPrinter, IconSend, IconPlayerSkipForward } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatCurrency } from '@/lib/formatCurrency';
import type { PageProps, PaginationLink } from '@/types';

interface ReceiptRow {
    uuid: string;
    receipt_number: string;
    issued_at: string | null;
    amount: string;
    payment_uuid: string;
    customer_uuid: string;
    customer_name: string;
    zone_name: string | null;
    phone: string | null;
    email: string | null;
    download_url: string;
    channel: 'whatsapp' | 'email' | 'print';
    problem: string | null;
}

interface BillRow {
    uuid: string;
    customer_uuid: string;
    customer_name: string;
    zone_name: string | null;
    phone: string | null;
    total_bill: string;
    wa_link: string | null;
    problem: string | null;
}

interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    meta: { current_page: number; last_page: number; total: number };
}

interface SendQueueProps {
    tab: 'receipts' | 'bills';
    can: { receipts: boolean; bills: boolean };
    counts: { receipts: number; bills: number };
    bill_period: string | null;
    bill_period_label: string | null;
    receipt_window_days: number;
    receipts: Paginated<ReceiptRow> | null;
    bills: Paginated<BillRow> | null;
}

const actionClass =
    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold shadow-sm ring-1 ring-inset transition-colors disabled:cursor-not-allowed disabled:opacity-50';

/**
 * "To send" — receipts and this month's bills that haven't reached the
 * customer yet (SendQueueController). Each row is one click: WhatsApp opens
 * with the message ready and the row leaves the list; email queues the PDF;
 * a printed-receipt customer's row prints a 58/80 mm roll. "Skip" clears a
 * row that was handled some other way.
 */
export default function SendQueueIndex({ tab, can, counts, bill_period_label, receipt_window_days, receipts, bills }: SendQueueProps) {
    const [busy, setBusy] = useState<string | null>(null);

    function post(url: string, key: string, onSuccess?: (page: { props: unknown }) => void) {
        router.post(url, {}, { preserveScroll: true, onStart: () => setBusy(key), onFinish: () => setBusy(null), onSuccess });
    }

    function sendReceiptWhatsapp(row: ReceiptRow) {
        post(`/payments/${row.payment_uuid}/receipt/send-whatsapp`, row.uuid, (page) => {
            const url = (page.props as PageProps).flash?.whatsapp_url;
            if (url) {
                window.open(url, '_blank', 'noopener,noreferrer');
            }
        });
    }

    function printReceipt(row: ReceiptRow, format: 'thermal58' | 'thermal80') {
        window.open(`${row.download_url}?format=${format}`, '_blank', 'noopener,noreferrer');
        // The print is counted server-side; refresh so the row drops off.
        window.setTimeout(() => router.reload({ only: ['receipts', 'counts'] }), 1500);
    }

    function sendBill(row: BillRow) {
        if (!row.wa_link) return;
        window.open(row.wa_link, '_blank', 'noopener,noreferrer');
        post(`/manuscripts/${row.customer_uuid}/send-bill`, row.uuid);
    }

    const tabs = [
        ...(can.receipts ? [{ key: 'receipts', label: 'Receipts', count: counts.receipts }] : []),
        ...(can.bills ? [{ key: 'bills', label: bill_period_label ? `Bills · ${bill_period_label}` : 'Bills', count: counts.bills }] : []),
    ];

    return (
        <AppLayout title="To send">
            <Head title="To send" />

            <div className="animate-fade-up mb-4 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 text-green-700">
                    <IconSend size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl text-slate-900">To send</h1>
                    <p className="text-sm text-slate-500">Receipts and bills that haven't reached the customer yet. Each one leaves the list once it's sent.</p>
                </div>
            </div>

            <div className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
                {tabs.map((item) => (
                    <Link
                        key={item.key}
                        href={`/to-send?tab=${item.key}`}
                        preserveScroll
                        className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium whitespace-nowrap ${
                            tab === item.key ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'
                        }`}
                    >
                        {item.label}
                        <span className={`ml-2 rounded-full px-2 py-0.5 text-xs ${item.count > 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-500'}`}>{item.count}</span>
                    </Link>
                ))}
            </div>

            {tab === 'receipts' && receipts && (
                <>
                    <p className="mb-3 text-xs text-slate-500">Receipts from the last {receipt_window_days} days. The action follows how each customer wants their receipts.</p>
                    {receipts.data.length === 0 ? (
                        <EmptyState title="All receipts are sent" description="New receipts appear here as payments are verified." />
                    ) : (
                        <>
                            <Table label="Receipts to send">
                                <TableHead>
                                    <Th>Receipt</Th>
                                    <Th>Customer</Th>
                                    <Th className="text-right">Amount</Th>
                                    <Th>Send by</Th>
                                    <Th className="text-right">Actions</Th>
                                </TableHead>
                                <TableBody>
                                    {receipts.data.map((row) => (
                                        <tr key={row.uuid}>
                                            <Td>
                                                <div className="font-medium text-slate-800">{row.receipt_number}</div>
                                                <div className="text-xs text-slate-500">{row.issued_at ? new Date(row.issued_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : ''}</div>
                                            </Td>
                                            <Td>
                                                <Link href={`/customers/${row.customer_uuid}`} className="font-medium text-slate-800 hover:underline">
                                                    {row.customer_name}
                                                </Link>
                                                <div className="text-xs text-slate-500">{row.zone_name ?? '—'}</div>
                                            </Td>
                                            <Td className="text-right tabular-nums">{formatCurrency(row.amount)}</Td>
                                            <Td>
                                                <span className="text-sm text-slate-700">
                                                    {row.channel === 'email' ? `Email · ${row.email}` : row.channel === 'print' ? 'Printed receipt' : `WhatsApp · ${row.phone ?? '—'}`}
                                                </span>
                                                {row.problem && <div className="text-xs text-amber-700">{row.problem}</div>}
                                            </Td>
                                            <Td className="text-right">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {row.channel === 'whatsapp' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => sendReceiptWhatsapp(row)}
                                                            disabled={row.problem !== null || busy === row.uuid}
                                                            className={`${actionClass} bg-green-600 text-white ring-green-600 hover:bg-green-700`}
                                                        >
                                                            <IconBrandWhatsapp size={16} /> Send
                                                        </button>
                                                    )}
                                                    {row.channel === 'email' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => post(`/payments/${row.payment_uuid}/receipt/send-email`, row.uuid)}
                                                            disabled={busy === row.uuid}
                                                            className={`${actionClass} bg-blue-600 text-white ring-blue-600 hover:bg-blue-700`}
                                                        >
                                                            <IconMail size={16} /> Email
                                                        </button>
                                                    )}
                                                    {row.channel === 'print' &&
                                                        (['thermal58', 'thermal80'] as const).map((format) => (
                                                            <button
                                                                key={format}
                                                                type="button"
                                                                onClick={() => printReceipt(row, format)}
                                                                className={`${actionClass} bg-white text-slate-700 ring-slate-300 hover:bg-slate-50`}
                                                            >
                                                                <IconPrinter size={16} /> {format === 'thermal58' ? '58 mm' : '80 mm'}
                                                            </button>
                                                        ))}
                                                    <button
                                                        type="button"
                                                        onClick={() => post(`/to-send/receipts/${row.uuid}/skip`, row.uuid)}
                                                        disabled={busy === row.uuid}
                                                        title="Already handed over or sent some other way"
                                                        className={`${actionClass} bg-white text-slate-500 ring-slate-200 hover:bg-slate-50`}
                                                    >
                                                        <IconPlayerSkipForward size={16} /> Skip
                                                    </button>
                                                </div>
                                            </Td>
                                        </tr>
                                    ))}
                                </TableBody>
                            </Table>
                            <Pagination links={receipts.links} />
                        </>
                    )}
                </>
            )}

            {tab === 'bills' && bills && (
                <>
                    <p className="mb-3 text-xs text-slate-500">
                        {bill_period_label ? `${bill_period_label} bills` : 'Bills'} for active customers with something to pay. WhatsApp opens with the bill message ready to send.
                    </p>
                    {bills.data.length === 0 ? (
                        <EmptyState title="All bills are sent" description="Bills appear here after each month's billing run." />
                    ) : (
                        <>
                            <Table label="Bills to send">
                                <TableHead>
                                    <Th>Customer</Th>
                                    <Th>Phone</Th>
                                    <Th className="text-right">Amount due</Th>
                                    <Th className="text-right">Actions</Th>
                                </TableHead>
                                <TableBody>
                                    {bills.data.map((row) => (
                                        <tr key={row.uuid}>
                                            <Td>
                                                <Link href={`/customers/${row.customer_uuid}`} className="font-medium text-slate-800 hover:underline">
                                                    {row.customer_name}
                                                </Link>
                                                <div className="text-xs text-slate-500">{row.zone_name ?? '—'}</div>
                                            </Td>
                                            <Td>
                                                {row.phone ?? '—'}
                                                {row.problem && <div className="text-xs text-amber-700">{row.problem}</div>}
                                            </Td>
                                            <Td className="text-right tabular-nums">{formatCurrency(row.total_bill)}</Td>
                                            <Td className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => sendBill(row)}
                                                        disabled={!row.wa_link || busy === row.uuid}
                                                        className={`${actionClass} bg-green-600 text-white ring-green-600 hover:bg-green-700`}
                                                    >
                                                        <IconBrandWhatsapp size={16} /> Send
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => post(`/to-send/bills/${row.uuid}/skip`, row.uuid)}
                                                        disabled={busy === row.uuid}
                                                        title="Already delivered some other way"
                                                        className={`${actionClass} bg-white text-slate-500 ring-slate-200 hover:bg-slate-50`}
                                                    >
                                                        <IconPlayerSkipForward size={16} /> Skip
                                                    </button>
                                                </div>
                                            </Td>
                                        </tr>
                                    ))}
                                </TableBody>
                            </Table>
                            <Pagination links={bills.links} />
                        </>
                    )}
                </>
            )}
        </AppLayout>
    );
}
