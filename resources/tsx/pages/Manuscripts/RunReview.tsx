import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { IconArrowLeft, IconCircleCheck, IconAlertTriangle, IconClock, IconSearch } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { TextInput } from '@/components/ui/TextInput';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { ManuscriptRunSummary } from '@/components/manuscripts/ManuscriptRunSummary';
import { PreRunReviewPanel } from '@/components/manuscripts/PreRunReviewPanel';
import { usePreRunReview } from '@/hooks/usePreRunReview';
import { formatCurrency } from '@/lib/formatCurrency';
import type { CommandRunEntry } from '@/types';

interface ComputedRow {
    customer_uuid: string | null;
    customer_name: string;
    customer_code: string | null;
    phone: string | null;
    zone_name: string | null;
    level: string | null;
    status: string | null;
    is_frozen: boolean;
    bill: string | null;
    total_arrears: string | null;
    credit: string | null;
    total_bill: string | null;
    payment_expiration: string | null;
    prepaid_months_remaining: number;
    payments_applied: number;
    adjustments_applied: number;
}

interface ManuscriptsRunReviewProps {
    run: CommandRunEntry;
    computed_rows: ComputedRow[] | null;
    canPublish: boolean;
}

type RowFilter = 'all' | 'frozen' | 'arrears' | 'credit' | 'adjusted';

/**
 * The new, lightweight "just clicked Calculate — watch it compute, then
 * review and publish" screen (task-scheduler.md's 2026-08-27 stage 3
 * addendum) — reachable in exactly one click from Manuscripts/Index.tsx
 * (ManuscriptController::calculate()'s redirect target). Deliberately NOT a
 * redirect to Settings > Command Runs: that page is built for reviewing a
 * run from hours ago among many, not for standing here watching the one an
 * admin just triggered.
 *
 * Same `computed_result_summary`/`batch_progress` field shapes
 * Settings/CommandRuns.tsx already renders (ManuscriptController::
 * runReview() shapes $run identically to that page's per-row shaping) — the
 * extracted ManuscriptRunSummary component and job_batches-backed progress
 * numbers below read exactly like that page's, just for one run instead of
 * a paginated list.
 */
export default function ManuscriptsRunReview({ run, computed_rows: computedRows, canPublish }: ManuscriptsRunReviewProps) {
    // Same job_batches-backed progress poll SettingsCommandRunController's
    // 'queued' rows already surface (batch_progress), polled here via
    // Inertia's usePoll — the identical primitive AppLayout.tsx's
    // notification bell already uses (`usePoll(20000, { only:
    // ['notifications'] })`). Only polls while this run is still computing;
    // stops itself the moment the run leaves 'queued' so a finished/
    // published/failed run never keeps polling in the background.
    const { stop, start } = usePoll(3000, { only: ['run'] }, { autoStart: false });

    useEffect(() => {
        if (run.status === 'queued') {
            start();
        } else {
            stop();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [run.status]);

    // The pre-run review list from Task A, still relevant context once the
    // run lands at pending_review — an admin reviewing the computed numbers
    // may want the same "who wasn't covered" list right here rather than
    // reopening the Calculate modal.
    const preRunReview = usePreRunReview(run.period, undefined, run.status === 'pending_review');

    function publish() {
        router.post(`/settings/command-runs/${run.uuid}/publish`, {}, { preserveScroll: true });
    }

    const [rowSearch, setRowSearch] = useState('');
    const [rowFilter, setRowFilter] = useState<RowFilter>('all');

    const visibleRows = useMemo(() => {
        if (!computedRows) return [];
        const q = rowSearch.trim().toLowerCase();
        return computedRows.filter((r) => {
            if (q && !r.customer_name.toLowerCase().includes(q) && !(r.phone ?? '').includes(q)) return false;
            if (rowFilter === 'frozen') return r.is_frozen;
            if (rowFilter === 'arrears') return Number(r.total_arrears ?? 0) > 0;
            if (rowFilter === 'credit') return Number(r.credit ?? 0) > 0;
            if (rowFilter === 'adjusted') return r.adjustments_applied > 0;
            return true;
        });
    }, [computedRows, rowSearch, rowFilter]);

    const rowFilters: { key: RowFilter; label: string }[] = [
        { key: 'all', label: 'All' },
        { key: 'frozen', label: 'Frozen' },
        { key: 'arrears', label: 'Has arrears' },
        { key: 'credit', label: 'Has credit' },
        { key: 'adjusted', label: 'Adjusted' },
    ];

    const progress = run.batch_progress;
    const progressLabel =
        progress && progress.total > 0 ? `${progress.total - progress.pending}/${progress.total} customers computed` : 'Starting…';

    const batchFailure = typeof run.metadata?.batch_failure === 'string' ? run.metadata.batch_failure : null;

    return (
        <AppLayout title="Manuscript Run">
            <Head title={`Manuscript Run — ${run.period}`} />

            <div className="animate-fade-up mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <Link
                        href="/manuscripts"
                        className="mb-1 inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700"
                    >
                        <IconArrowLeft size={16} stroke={1.75} />
                        Back to Manuscripts
                    </Link>
                    <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">Manuscript Run — {run.period}</h1>
                </div>
                {run.status === 'queued' && (
                    <Badge tone="blue">
                        <IconClock size={12} className="mr-1 inline" stroke={2} />
                        Running
                    </Badge>
                )}
                {run.status === 'pending_review' && <Badge tone="yellow">Awaiting Review</Badge>}
                {run.status === 'published' && <Badge tone="green">Published</Badge>}
                {run.status === 'failed' && <Badge tone="red">Failed</Badge>}
            </div>

            {run.status === 'queued' && (
                <Card className="animate-fade-up">
                    <CardBody className="flex flex-col items-center gap-3 py-10 text-center">
                        <LoadingSpinner className="h-6 w-6 text-blue-600" />
                        <p className="text-sm font-medium text-slate-700">Computing period {run.period}…</p>
                        <p className="text-xs text-slate-500">{progressLabel}</p>
                        <p className="max-w-md text-xs text-slate-400">
                            This page checks back automatically every few seconds — no need to refresh. It&apos;s safe to
                            navigate away; you&apos;ll find this run again from Settings → Command Runs.
                        </p>
                    </CardBody>
                </Card>
            )}

            {run.status === 'pending_review' && run.computed_result_summary && (
                <div className="flex flex-col gap-4">
                    <Card className="animate-fade-up">
                        <CardHeader>
                            <h2 className="text-sm font-semibold text-slate-900">Computed Result</h2>
                        </CardHeader>
                        <CardBody className="flex flex-col gap-4">
                            <p className="text-xs text-slate-500">
                                This is exactly what will be committed to Manuscripts when published — recomputing does not
                                happen at publish time, so these numbers won&apos;t drift even if new payments arrive before
                                then.
                            </p>
                            <ManuscriptRunSummary summary={run.computed_result_summary} />
                            {canPublish && (
                                <Button type="button" onClick={publish} className="w-full justify-center sm:w-auto">
                                    Publish this period
                                </Button>
                            )}
                        </CardBody>
                    </Card>

                    {computedRows && computedRows.length > 0 && (
                        <Card className="animate-fade-up [animation-delay:60ms]">
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h2 className="text-sm font-semibold text-slate-900">
                                        Computed Manuscript — Preview ({computedRows.length} customers)
                                    </h2>
                                </div>
                            </CardHeader>
                            <CardBody className="flex flex-col gap-3">
                                <p className="text-xs text-slate-500">
                                    The exact per-customer figures Publish will write. Nothing here is live yet — query the
                                    period after publishing to see it in Manuscripts.
                                </p>
                                <div className="flex flex-wrap items-center gap-2">
                                    <div className="relative">
                                        <IconSearch
                                            size={14}
                                            stroke={1.75}
                                            className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400"
                                        />
                                        <TextInput
                                            id="row-search"
                                            placeholder="Name or phone"
                                            value={rowSearch}
                                            onChange={(e) => setRowSearch(e.target.value)}
                                            className="w-52 pl-8"
                                        />
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        {rowFilters.map((f) => (
                                            <button
                                                key={f.key}
                                                type="button"
                                                onClick={() => setRowFilter(f.key)}
                                                className={`rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors ${
                                                    rowFilter === f.key
                                                        ? 'bg-blue-600 text-white'
                                                        : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'
                                                }`}
                                            >
                                                {f.label}
                                            </button>
                                        ))}
                                    </div>
                                    <span className="text-xs text-slate-400">{visibleRows.length} shown</span>
                                </div>
                                <div className="max-h-[32rem] overflow-auto rounded-lg ring-1 ring-slate-200">
                                    <Table>
                                        <TableHead>
                                            <Th>Name</Th>
                                            <Th>Zone</Th>
                                            <Th>Bill</Th>
                                            <Th>Arrears</Th>
                                            <Th>Credit</Th>
                                            <Th>Total Bill</Th>
                                            <Th>Prepaid</Th>
                                            <Th>Applied</Th>
                                        </TableHead>
                                        <TableBody>
                                            {visibleRows.map((r) => (
                                                <tr key={r.customer_uuid ?? r.customer_name} className="hover:bg-slate-50/70">
                                                    <Td>
                                                        <span className="font-medium text-slate-800">{r.customer_name}</span>
                                                        {r.is_frozen && (
                                                            <span className="ml-1.5 inline-flex items-center rounded-full bg-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">
                                                                frozen
                                                            </span>
                                                        )}
                                                        {r.phone && <span className="block text-xs text-slate-400">{r.phone}</span>}
                                                    </Td>
                                                    <Td className="text-xs text-slate-500">{r.zone_name ?? '—'}</Td>
                                                    <Td>{r.bill ? formatCurrency(r.bill) : '—'}</Td>
                                                    <Td className={Number(r.total_arrears ?? 0) > 0 ? 'font-medium text-red-700' : ''}>
                                                        {r.total_arrears ? formatCurrency(r.total_arrears) : '—'}
                                                    </Td>
                                                    <Td className={Number(r.credit ?? 0) > 0 ? 'font-medium text-green-700' : ''}>
                                                        {r.credit ? formatCurrency(r.credit) : '—'}
                                                    </Td>
                                                    <Td className="font-semibold text-slate-900">
                                                        {r.total_bill ? formatCurrency(r.total_bill) : '—'}
                                                    </Td>
                                                    <Td className="text-xs text-slate-500">
                                                        {r.prepaid_months_remaining > 0
                                                            ? `${r.prepaid_months_remaining} mo`
                                                            : r.payment_expiration
                                                              ? r.payment_expiration
                                                              : '—'}
                                                    </Td>
                                                    <Td className="text-xs text-slate-500">
                                                        {r.payments_applied > 0 && `${r.payments_applied} pmt`}
                                                        {r.payments_applied > 0 && r.adjustments_applied > 0 && ', '}
                                                        {r.adjustments_applied > 0 && `${r.adjustments_applied} adj`}
                                                        {r.payments_applied === 0 && r.adjustments_applied === 0 && '—'}
                                                    </Td>
                                                </tr>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardBody>
                        </Card>
                    )}

                    <Card className="animate-fade-up [animation-delay:80ms]">
                        <CardHeader>
                            <h2 className="text-sm font-semibold text-slate-900">Who Still Isn&apos;t Covered</h2>
                        </CardHeader>
                        <CardBody>
                            <PreRunReviewPanel
                                period={run.period}
                                loading={preRunReview.loading}
                                error={preRunReview.error}
                                data={preRunReview.data}
                                onReload={preRunReview.reload}
                            />
                        </CardBody>
                    </Card>
                </div>
            )}

            {run.status === 'published' && (
                <Card className="animate-fade-up">
                    <CardBody className="flex flex-col items-center gap-3 py-10 text-center">
                        <IconCircleCheck size={32} className="text-green-600" stroke={1.75} />
                        <p className="text-sm font-medium text-slate-700">Period {run.period} is published.</p>
                        <Link href={`/manuscripts?period=${run.period}`} className="text-sm font-medium text-blue-600 hover:text-blue-700">
                            View the manuscript
                        </Link>
                    </CardBody>
                </Card>
            )}

            {run.status === 'failed' && (
                <Card className="animate-fade-up">
                    <CardBody className="flex flex-col items-center gap-3 py-10 text-center">
                        <IconAlertTriangle size={32} className="text-red-600" stroke={1.75} />
                        <p className="text-sm font-medium text-slate-700">This run failed.</p>
                        {batchFailure && <p className="max-w-md text-xs text-slate-500">{batchFailure}</p>}
                        <Link href="/manuscripts" className="text-sm font-medium text-blue-600 hover:text-blue-700">
                            Back to Manuscripts
                        </Link>
                    </CardBody>
                </Card>
            )}
        </AppLayout>
    );
}
