import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import { IconArrowLeft, IconCircleCheck, IconAlertTriangle, IconClock } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ManuscriptRunSummary } from '@/components/manuscripts/ManuscriptRunSummary';
import { PreRunReviewPanel } from '@/components/manuscripts/PreRunReviewPanel';
import { usePreRunReview } from '@/hooks/usePreRunReview';
import type { CommandRunEntry } from '@/types';

interface ManuscriptsRunReviewProps {
    run: CommandRunEntry;
    canPublish: boolean;
}

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
export default function ManuscriptsRunReview({ run, canPublish }: ManuscriptsRunReviewProps) {
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

                    <Card className="animate-fade-up [animation-delay:60ms]">
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
