import { FormEvent, useEffect, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { IconBook, IconDeviceMobile, IconPrinter, IconSearch } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { formatCurrency } from '@/lib/formatCurrency';

interface BlockRow {
    uuid: string;
    number: string;
    kind: 'digital' | 'book';
    status: 'open' | 'closed';
    holder_name: string | null;
    device_name: string | null;
    first_number: string;
    last_number: string;
    size: number;
    counts: { used: number; cancelled: number; unused: number; scanned_unused: number };
    /** The printed book's PDF: null = never built, pending = the queue is building it. */
    pdf_status: 'pending' | 'ready' | 'failed' | null;
    created_at: string | null;
}

interface DeviceRow {
    uuid: string;
    name: string;
    user_name: string | null;
    registered_at: string | null;
    last_seen_at: string | null;
    revoked_at: string | null;
}

interface Lookup {
    number: string;
    found: boolean;
    leaf_status?: 'unused' | 'used' | 'cancelled' | null;
    block_number?: string | null;
    holder_name?: string | null;
    cancelled_reason?: string | null;
    cancelled_by_name?: string | null;
    scanned_unused_at?: string | null;
    receipt?: {
        status: 'issued' | 'void';
        source: 'office' | 'digital' | 'book';
        amount: string;
        issued_at: string | null;
        customer_name: string | null;
        payment_uuid: string | null;
    } | null;
}

interface Props {
    filters: { status: 'open' | 'closed' | 'all'; search: string };
    lookup: Lookup | null;
    blocks: BlockRow[];
    devices: DeviceRow[];
    holders: { id: number; name: string; role: string }[];
    book_sizes: number[];
}

const dateTime = (value: string | null | undefined) => (value ? new Date(value).toLocaleString() : '—');

/**
 * Receipt stock — App\Http\Controllers\ReceiptStockController
 * (docs/plans/cash-controls.md, wave 2). Every receipt number is issued by
 * the system to one person; this page shows who holds which numbers and
 * whether each has been used or cancelled. Unused numbers in a closed block,
 * or numbers a customer scanned before any payment was recorded on them,
 * are what to chase.
 */
export default function ReceiptStockIndex({ filters, lookup, blocks, devices, holders, book_sizes }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [issueOpen, setIssueOpen] = useState(false);
    const [closing, setClosing] = useState<BlockRow | null>(null);

    // While a book PDF is being prepared, refresh the blocks every few
    // seconds so Print appears by itself when the queue finishes.
    const anyPreparing = blocks.some((block) => block.pdf_status === 'pending');
    useEffect(() => {
        if (!anyPreparing) {
            return;
        }

        const timer = setInterval(() => router.reload({ only: ['blocks'] }), 4000);

        return () => clearInterval(timer);
    }, [anyPreparing]);

    function submitSearch(e: FormEvent) {
        e.preventDefault();
        router.get('/receipt-stock', { status: filters.status, search: search || undefined }, { preserveState: true });
    }

    const totals = blocks.reduce(
        (sum, block) => ({
            unused: sum.unused + block.counts.unused,
            scanned: sum.scanned + block.counts.scanned_unused,
            unaccounted: sum.unaccounted + (block.status === 'closed' ? block.counts.unused : 0),
        }),
        { unused: 0, scanned: 0, unaccounted: 0 },
    );

    return (
        <AppLayout title="Receipt stock" breadcrumbs={[{ label: 'Receipt stock' }]}>
            <Head title="Receipt stock" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl text-slate-900">Receipt stock</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-500">
                        Every receipt number is created by the system and handed to one person. Each must end used or cancelled with a reason.
                    </p>
                </div>
                <Button onClick={() => setIssueOpen(true)}>
                    <IconBook size={16} stroke={1.75} /> Issue receipt book
                </Button>
            </div>

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <Summary label="Unused numbers in circulation" value={totals.unused} />
                <Summary label="Scanned by a customer before being recorded" value={totals.scanned} tone={totals.scanned > 0 ? 'red' : 'slate'} />
                <Summary label="Unaccounted (closed blocks)" value={totals.unaccounted} tone={totals.unaccounted > 0 ? 'red' : 'slate'} />
            </div>

            <Card className="mb-6">
                <CardBody>
                    <form onSubmit={submitSearch} className="flex flex-col gap-2 sm:flex-row sm:items-end">
                        <div className="flex-1">
                            <TextInput
                                id="search"
                                label="Look up a receipt number"
                                placeholder="RCP-2026-000123"
                                value={search}
                                onChange={(e) => setSearch(e.target.value.toUpperCase())}
                            />
                        </div>
                        <Button type="submit" variant="secondary">
                            <IconSearch size={16} stroke={1.75} /> Look up
                        </Button>
                    </form>
                    {lookup && <LookupResult lookup={lookup} />}
                </CardBody>
            </Card>

            <div className="mb-3 inline-flex rounded-lg bg-slate-100 p-1">
                {(['open', 'closed', 'all'] as const).map((status) => (
                    <button
                        key={status}
                        type="button"
                        onClick={() => router.get('/receipt-stock', { status }, { preserveScroll: true })}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium capitalize ${
                            filters.status === status ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        {status}
                    </button>
                ))}
            </div>

            <Card className="mb-8">
                {blocks.length === 0 ? (
                    <EmptyState title="No receipt blocks" description="Books you issue and the numbers phones draw automatically appear here." />
                ) : (
                    <Table label="Receipt blocks">
                        <TableHead>
                            <Th>Block</Th>
                            <Th>Held by</Th>
                            <Th>Numbers</Th>
                            <Th>Used</Th>
                            <Th>Cancelled</Th>
                            <Th>Unused</Th>
                            <Th className="text-right">Actions</Th>
                        </TableHead>
                        <TableBody>
                            {blocks.map((block) => (
                                <tr key={block.uuid}>
                                    <Td>
                                        <div className="font-medium text-slate-900">{block.number}</div>
                                        <div className="mt-0.5 flex items-center gap-1.5">
                                            <Badge tone={block.kind === 'book' ? 'blue' : 'slate'}>{block.kind === 'book' ? 'Printed book' : 'Phone'}</Badge>
                                            {block.status === 'closed' && <Badge>Closed</Badge>}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div>{block.holder_name ?? '—'}</div>
                                        {block.device_name && <div className="text-xs text-slate-500">{block.device_name}</div>}
                                    </Td>
                                    <Td>
                                        <div className="font-mono text-xs">
                                            {block.first_number} – {block.last_number.slice(-6)}
                                        </div>
                                        <div className="text-xs text-slate-500">{block.size} numbers</div>
                                    </Td>
                                    <Td>{block.counts.used}</Td>
                                    <Td>{block.counts.cancelled}</Td>
                                    <Td>
                                        <span className={block.status === 'closed' && block.counts.unused > 0 ? 'font-semibold text-red-700' : ''}>
                                            {block.counts.unused}
                                        </span>
                                        {block.counts.scanned_unused > 0 && (
                                            <div className="text-xs font-medium text-red-700">{block.counts.scanned_unused} scanned before recording</div>
                                        )}
                                    </Td>
                                    <Td className="text-right">
                                        <div className="flex justify-end gap-2">
                                            {block.kind === 'book' && <BookPdfButton block={block} />}
                                            {block.status === 'open' && (
                                                <Button variant="ghost" onClick={() => setClosing(block)}>
                                                    Close
                                                </Button>
                                            )}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>

            <Card>
                <CardHeader>
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <IconDeviceMobile size={16} stroke={1.75} /> Phones registered for receipts
                    </h3>
                </CardHeader>
                {devices.length === 0 ? (
                    <EmptyState title="No phones registered yet" description="A collector's phone registers itself the first time they open the app." />
                ) : (
                    <Table label="Registered phones">
                        <TableHead>
                            <Th>Phone</Th>
                            <Th>Collector</Th>
                            <Th>Last seen</Th>
                            <Th className="text-right">Status</Th>
                        </TableHead>
                        <TableBody>
                            {devices.map((device) => (
                                <tr key={device.uuid}>
                                    <Td>{device.name}</Td>
                                    <Td>{device.user_name ?? '—'}</Td>
                                    <Td>{dateTime(device.last_seen_at)}</Td>
                                    <Td className="text-right">
                                        {device.revoked_at ? (
                                            <Badge tone="red">Revoked</Badge>
                                        ) : (
                                            <Button
                                                variant="danger"
                                                onClick={() => {
                                                    if (window.confirm(`Revoke ${device.name}? Receipts signed on it will no longer be accepted.`)) {
                                                        router.post(`/receipt-stock/devices/${device.uuid}/revoke`, {}, { preserveScroll: true });
                                                    }
                                                }}
                                            >
                                                Revoke (lost phone)
                                            </Button>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>

            <IssueBookModal open={issueOpen} onClose={() => setIssueOpen(false)} holders={holders} sizes={book_sizes} />
            <CloseBlockModal block={closing} onClose={() => setClosing(null)} />
        </AppLayout>
    );
}

/**
 * The book PDF is built by a queued job (GenerateReceiptBookPdfJob), not in
 * the request — a 100-leaf book takes longer than a request may run.
 */
function BookPdfButton({ block }: { block: BlockRow }) {
    if (block.pdf_status === 'ready') {
        return (
            <a href={`/receipt-stock/${block.uuid}/pdf`}>
                <Button variant="secondary">
                    <IconPrinter size={16} stroke={1.75} /> Print
                </Button>
            </a>
        );
    }

    if (block.pdf_status === 'pending') {
        return (
            <Button variant="secondary" disabled>
                <IconPrinter size={16} stroke={1.75} /> Preparing…
            </Button>
        );
    }

    return (
        <Button variant="secondary" onClick={() => router.post(`/receipt-stock/${block.uuid}/pdf`, {}, { preserveScroll: true })}>
            <IconPrinter size={16} stroke={1.75} /> {block.pdf_status === 'failed' ? 'Retry PDF' : 'Prepare PDF'}
        </Button>
    );
}

function Summary({ label, value, tone = 'slate' }: { label: string; value: number; tone?: 'slate' | 'red' }) {
    return (
        <Card>
            <CardBody>
                <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
                <div className={`mt-1 text-2xl font-semibold ${tone === 'red' ? 'text-red-700' : 'text-slate-900'}`}>{value}</div>
            </CardBody>
        </Card>
    );
}

function LookupResult({ lookup }: { lookup: Lookup }) {
    const [reason, setReason] = useState('');

    if (!lookup.found) {
        return (
            <p className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-800">
                <strong>{lookup.number}</strong> was never issued by this system. A receipt carrying it is not genuine.
            </p>
        );
    }

    return (
        <div className="mt-3 rounded-lg border border-slate-200 p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono font-semibold">{lookup.number}</span>
                {lookup.leaf_status && <Badge tone={lookup.leaf_status === 'used' ? 'green' : lookup.leaf_status === 'cancelled' ? 'red' : 'yellow'}>{lookup.leaf_status}</Badge>}
                {lookup.receipt?.status === 'void' && <Badge tone="red">Void</Badge>}
            </div>
            <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-slate-600">
                {lookup.holder_name && (
                    <>
                        <dt>Held by</dt>
                        <dd>
                            {lookup.holder_name} (block {lookup.block_number})
                        </dd>
                    </>
                )}
                {lookup.receipt && (
                    <>
                        <dt>Payment</dt>
                        <dd>
                            {formatCurrency(lookup.receipt.amount)} from {lookup.receipt.customer_name ?? '—'} · {dateTime(lookup.receipt.issued_at)}
                            {lookup.receipt.payment_uuid && (
                                <>
                                    {' '}
                                    <Link href={`/payments/${lookup.receipt.payment_uuid}`} className="font-medium text-blue-700 hover:underline">
                                        Open
                                    </Link>
                                </>
                            )}
                        </dd>
                    </>
                )}
                {lookup.cancelled_reason && (
                    <>
                        <dt>Cancelled</dt>
                        <dd>
                            {lookup.cancelled_reason} — {lookup.cancelled_by_name ?? '—'}
                        </dd>
                    </>
                )}
                {lookup.scanned_unused_at && (
                    <>
                        <dt>Warning</dt>
                        <dd className="font-medium text-red-700">A customer scanned this receipt on {dateTime(lookup.scanned_unused_at)} before any payment was recorded on it.</dd>
                    </>
                )}
            </dl>
            {lookup.leaf_status === 'unused' && (
                <form
                    className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(`/receipt-stock/numbers/${lookup.number}/cancel`, { reason }, { preserveScroll: true });
                    }}
                >
                    <div className="flex-1">
                        <TextInput id="cancel_reason" label="Cancel this unused number — reason" value={reason} onChange={(e) => setReason(e.target.value)} required />
                    </div>
                    <Button type="submit" variant="warning">
                        Cancel number
                    </Button>
                </form>
            )}
        </div>
    );
}

function IssueBookModal({
    open,
    onClose,
    holders,
    sizes,
}: {
    open: boolean;
    onClose: () => void;
    holders: Props['holders'];
    sizes: number[];
}) {
    const { data, setData, post, processing, errors, reset } = useForm({ holder_user_id: '', size: String(sizes[1] ?? sizes[0] ?? 50) });

    return (
        <Modal open={open} onClose={onClose} title="Issue a receipt book">
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/receipt-stock/books', {
                        onSuccess: () => {
                            reset();
                            onClose();
                        },
                    });
                }}
            >
                <p className="text-sm text-slate-500">
                    The system reserves the next receipt numbers for this person and prints them as a book. Only they can record payments on these numbers.
                </p>
                <SelectInput label="Hand it to" value={data.holder_user_id} onChange={(e) => setData('holder_user_id', e.target.value)} error={errors.holder_user_id} required>
                    <option value="">Choose a person…</option>
                    {holders.map((holder) => (
                        <option key={holder.id} value={holder.id}>
                            {holder.name} ({holder.role})
                        </option>
                    ))}
                </SelectInput>
                <SelectInput label="Number of leaves" value={data.size} onChange={(e) => setData('size', e.target.value)} error={errors.size}>
                    {sizes.map((size) => (
                        <option key={size} value={size}>
                            {size}
                        </option>
                    ))}
                </SelectInput>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Issue book
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function CloseBlockModal({ block, onClose }: { block: BlockRow | null; onClose: () => void }) {
    const [cancelRemaining, setCancelRemaining] = useState(true);
    const [reason, setReason] = useState('Book returned — blank leaves checked');

    return (
        <Modal open={block !== null} onClose={onClose} title={block ? `Close ${block.number}` : ''}>
            {block && (
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            `/receipt-stock/${block.uuid}/close`,
                            { cancel_remaining: cancelRemaining, reason: cancelRemaining ? reason : null },
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                >
                    <p className="text-sm text-slate-600">
                        {block.counts.unused} number(s) in this block are still unused.
                    </p>
                    <label className="flex items-start gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={cancelRemaining}
                            onChange={(e) => setCancelRemaining(e.target.checked)}
                            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600"
                        />
                        <span>I have the unused leaves in hand — cancel them. Leave unticked if they are missing; they will show as unaccounted.</span>
                    </label>
                    {cancelRemaining && <TextInput id="close_reason" label="Reason" value={reason} onChange={(e) => setReason(e.target.value)} required />}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Back
                        </Button>
                        <Button type="submit" variant="warning">
                            Close block
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
