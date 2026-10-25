import { FormEvent, useMemo, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { IconGift, IconPlus } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatCurrency } from '@/lib/formatCurrency';
import { formatMonthYear } from '@/lib/formatMonthYear';

interface BatchRow {
    uuid: string;
    where: string;
    months: number;
    customers_count: number;
    note: string;
    status: string;
    requested_by: string | null;
    decided_by: string | null;
    created_at: string | null;
    decided_at: string | null;
    can_decide: boolean;
}

interface CustomerRow {
    uuid: string;
    customer_uuid: string | null;
    customer_name: string | null;
    services: string[];
    months: number;
    note: string | null;
    status: string;
    start_period: string | null;
    requested_by: string | null;
    decided_by: string | null;
    without_approval: boolean;
    created_at: string | null;
    can_decide: boolean;
}

interface CompensationIndexProps {
    waiting: { batches: BatchRow[]; customers: CustomerRow[] };
    given: { batches: BatchRow[]; customers: CustomerRow[] };
    cost: { free_months: string; customers: number; write_offs: string; total: string };
    period: string;
    can_request: boolean;
    max_months: number;
    months_without_approval: number;
    branches: { uuid: string; name: string; zones: { uuid: string; name: string }[] }[];
    services: { uuid: string; name: string }[];
}

/**
 * Compensation (CompensationController::index): what waits for approval,
 * what was given in the last 60 days, and this month's cost. A whole zone or
 * branch is compensated from here; one customer from their own page.
 */
export default function CompensationIndex({ waiting, given, cost, period, can_request, max_months, months_without_approval, branches, services }: CompensationIndexProps) {
    const [asking, setAsking] = useState(false);
    const waitingCount = waiting.batches.length + waiting.customers.length;

    return (
        <AppLayout title="Compensation">
            <Head title="Compensation" />

            <div className="animate-fade-up mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-pink-100 text-pink-700">
                        <IconGift size={20} stroke={1.75} />
                    </span>
                    <div>
                        <h1 className="font-display text-2xl text-slate-900">Compensation</h1>
                        <p className="text-sm text-slate-500">
                            Free months and write-offs for poor service. One customer: from their page
                            {months_without_approval > 0 ? ` (up to ${months_without_approval} month${months_without_approval === 1 ? '' : 's'} a year without approval)` : ''}. A
                            whole zone or branch: here, always approved by an admin.
                        </p>
                    </div>
                </div>
                {can_request && (
                    <Button type="button" onClick={() => setAsking(true)}>
                        <IconPlus size={16} stroke={2} />
                        Compensate a zone or branch
                    </Button>
                )}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label="Waiting for approval" value={String(waitingCount)} icon={<IconGift size={20} stroke={1.75} />} tone="blue" />
                <StatCard
                    label={`Free months running in ${formatMonthYear(period)}`}
                    value={formatCurrency(cost.free_months)}
                    icon={<IconGift size={20} stroke={1.75} />}
                    tone="green"
                />
                <StatCard label="Cost this month (free months + write-offs)" value={formatCurrency(cost.total)} icon={<IconGift size={20} stroke={1.75} />} tone="green" />
            </div>
            <p className="-mt-4 mb-6 text-xs text-slate-500">
                {cost.customers} customer{cost.customers === 1 ? '' : 's'} on compensation free months this month · write-offs approved this month:{' '}
                {formatCurrency(cost.write_offs)}.
            </p>

            <Section title="Waiting for approval">
                <Rows batches={waiting.batches} customers={waiting.customers} empty="Nothing is waiting." />
            </Section>

            <Section title="Given in the last 60 days">
                <Rows batches={given.batches} customers={given.customers} empty="No compensation given lately." />
            </Section>

            {can_request && (
                <AskModal open={asking} onClose={() => setAsking(false)} branches={branches} services={services} maxMonths={max_months} />
            )}
        </AppLayout>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <Card className="mb-6">
            <CardHeader>
                <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            </CardHeader>
            <CardBody>{children}</CardBody>
        </Card>
    );
}

function Rows({ batches, customers, empty }: { batches: BatchRow[]; customers: CustomerRow[]; empty: string }) {
    if (batches.length === 0 && customers.length === 0) {
        return <EmptyState title={empty} />;
    }

    return (
        <ul className="divide-y divide-slate-100">
            {batches.map((batch) => (
                <li key={batch.uuid} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-slate-900">
                            {batch.where}: {batch.months} free month{batch.months === 1 ? '' : 's'} · {batch.customers_count} customers{' '}
                            <Badge tone="blue">Zone / branch</Badge>
                        </p>
                        <p className="text-xs text-slate-500">
                            {batch.note} · asked by {batch.requested_by ?? '—'}
                            {batch.decided_by ? ` · approved by ${batch.decided_by}` : ''}
                        </p>
                    </div>
                    {batch.can_decide && <Decide url={`/compensation/${batch.uuid}`} what={`${batch.customers_count} customers' free months`} />}
                </li>
            ))}
            {customers.map((row) => (
                <li key={row.uuid} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-slate-900">
                            {row.customer_uuid ? (
                                <Link href={`/customers/${row.customer_uuid}`} className="text-blue-600 hover:underline">
                                    {row.customer_name}
                                </Link>
                            ) : (
                                row.customer_name
                            )}
                            : {row.months} free month{row.months === 1 ? '' : 's'} · {row.services.join(', ')}
                            {row.without_approval && (
                                <>
                                    {' '}
                                    <Badge tone="slate">Within allowance</Badge>
                                </>
                            )}
                        </p>
                        <p className="text-xs text-slate-500">
                            {row.note ?? ''} · asked by {row.requested_by ?? '—'}
                            {row.decided_by && !row.without_approval ? ` · approved by ${row.decided_by}` : ''}
                            {row.start_period ? ` · from ${formatMonthYear(row.start_period)}` : ''}
                        </p>
                    </div>
                    {row.can_decide && <Decide url={`/free-months/${row.uuid}`} what={`${row.customer_name}'s free months`} />}
                </li>
            ))}
        </ul>
    );
}

function Decide({ url, what }: { url: string; what: string }) {
    const [busy, setBusy] = useState(false);

    function decide(decision: 'approve' | 'reject') {
        const reason = decision === 'reject' ? window.prompt(`Why reject ${what}? (optional)`) : null;
        if (decision === 'reject' && reason === null) {
            return;
        }

        router.post(`${url}/${decision}`, decision === 'reject' ? { reason } : {}, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    }

    return (
        <div className="flex shrink-0 gap-2">
            <Button type="button" variant="secondary" disabled={busy} onClick={() => decide('reject')}>
                Reject
            </Button>
            <Button type="button" disabled={busy} onClick={() => decide('approve')}>
                Approve
            </Button>
        </div>
    );
}

function AskModal({
    open,
    onClose,
    branches,
    services,
    maxMonths,
}: {
    open: boolean;
    onClose: () => void;
    branches: CompensationIndexProps['branches'];
    services: CompensationIndexProps['services'];
    maxMonths: number;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_uuid: branches.length === 1 ? branches[0].uuid : '',
        zone_uuid: '',
        service_uuids: [] as string[],
        months: '1',
        note: '',
    });

    const zones = useMemo(() => branches.find((branch) => branch.uuid === data.branch_uuid)?.zones ?? [], [branches, data.branch_uuid]);

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/compensation', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    }

    return (
        <Modal open={open} onClose={onClose} title="Compensate a zone or branch">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    Every active customer there gets the free months, after their existing free and paid months. An admin other than you approves it once
                    for everyone.
                </p>
                <SelectInput
                    id="comp-branch"
                    label="Branch"
                    required
                    value={data.branch_uuid}
                    onChange={(e) => {
                        setData((current) => ({ ...current, branch_uuid: e.target.value, zone_uuid: '' }));
                    }}
                    error={errors.branch_uuid}
                >
                    <option value="">Choose a branch…</option>
                    {branches.map((branch) => (
                        <option key={branch.uuid} value={branch.uuid}>
                            {branch.name}
                        </option>
                    ))}
                </SelectInput>
                <SelectInput
                    id="comp-zone"
                    label="Zone"
                    value={data.zone_uuid}
                    onChange={(e) => setData('zone_uuid', e.target.value)}
                    error={errors.zone_uuid ?? (errors as Record<string, string>).zone}
                    disabled={!data.branch_uuid}
                >
                    <option value="">The whole branch</option>
                    {zones.map((zone) => (
                        <option key={zone.uuid} value={zone.uuid}>
                            {zone.name}
                        </option>
                    ))}
                </SelectInput>
                <TextInput
                    id="comp-months"
                    label={`Months (1 – ${maxMonths})`}
                    type="number"
                    min="1"
                    max={maxMonths}
                    required
                    value={data.months}
                    onChange={(e) => setData('months', e.target.value)}
                    error={errors.months}
                />
                {services.length > 1 && (
                    <fieldset>
                        <legend className="mb-1 text-sm font-medium text-slate-700">Services (none ticked = all)</legend>
                        {services.map((service) => (
                            <label key={service.uuid} className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={data.service_uuids.includes(service.uuid)}
                                    onChange={(e) =>
                                        setData(
                                            'service_uuids',
                                            e.target.checked ? [...data.service_uuids, service.uuid] : data.service_uuids.filter((uuid) => uuid !== service.uuid),
                                        )
                                    }
                                    className="h-4 w-4 rounded border-slate-300"
                                />
                                {service.name}
                            </label>
                        ))}
                    </fieldset>
                )}
                <TextInput
                    id="comp-note"
                    label="Reason"
                    required
                    placeholder="e.g. Signal outage 3–10 October"
                    value={data.note}
                    onChange={(e) => setData('note', e.target.value)}
                    error={errors.note}
                />
                {(errors as Record<string, string>).branch && <p className="text-sm text-red-600">{(errors as Record<string, string>).branch}</p>}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Ask for approval
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
