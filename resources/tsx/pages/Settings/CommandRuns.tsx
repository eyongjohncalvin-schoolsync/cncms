import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { IconTerminal2, IconEye, IconCheck, IconClock, IconCalendarTime, IconLock, IconBan, IconTrash, IconArrowBackUp } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { SelectInput } from '@/components/ui/SelectInput';
import { Modal } from '@/components/ui/Modal';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Dropdown, DropdownItem, DropdownDivider } from '@/components/ui/Dropdown';
import { ManuscriptRunSummary } from '@/components/manuscripts/ManuscriptRunSummary';
import type { CommandRunEntry, CommandRunStatus, ManuscriptSchedule, PaginatedResponse } from '@/types';

function metadataSummary(metadata: Record<string, unknown> | null): string {
    if (!metadata || Object.keys(metadata).length === 0) {
        return '—';
    }

    return Object.entries(metadata)
        .map(([key, value]) => `${key}: ${value}`)
        .join(', ');
}

// manuscript:calculate (and, since task-scheduler.md, any manuscript_generation
// scheduler run) stores a numeric `errors` count in its metadata payload —
// see app/Console/Commands/ManuscriptCalculate.php and
// App\Services\ManuscriptGenerationBatchService::aggregateComputedResult(),
// which folds the same summary into `metadata` for exactly this reason.
// Surface that as an outcome badge when present; fall back to a neutral
// badge for any other command shape rather than guessing at fields that may
// not exist.
function outcomeBadge(metadata: Record<string, unknown> | null) {
    const errors = metadata && typeof metadata.errors === 'number' ? metadata.errors : null;

    if (errors === null) {
        return <Badge tone="slate">Info</Badge>;
    }

    if (errors > 0) {
        return (
            <Badge tone="red">
                {errors} error{errors === 1 ? '' : 's'}
            </Badge>
        );
    }

    return <Badge tone="green">Clean</Badge>;
}

// Distinct from outcomeBadge() above: this is the run's LIFECYCLE state
// (task-scheduler.md section 4 — queued while the Bus::batch() chunks are
// still running, pending_review for a scheduled run awaiting an admin's
// Publish, published once committed to `manuscripts`, failed if a whole
// chunk job crashed outright). outcomeBadge() is about per-customer error
// counts WITHIN a run that already reached published/pending_review.
function statusBadge(status: CommandRunStatus, progress: CommandRunEntry['batch_progress']) {
    switch (status) {
        case 'queued': {
            const label = progress && progress.total > 0 ? `Running (${progress.total - progress.pending}/${progress.total})` : 'Running';

            return (
                <Badge tone="blue">
                    <IconClock size={12} className="mr-1 inline" stroke={2} />
                    {label}
                </Badge>
            );
        }
        case 'pending_review':
            return <Badge tone="yellow">Awaiting Review</Badge>;
        case 'published':
            return <Badge tone="green">Published</Badge>;
        case 'failed':
            return <Badge tone="red">Failed</Badge>;
        case 'rolled_back':
            return <Badge tone="slate">Rolled Back</Badge>;
        default:
            return <Badge tone="slate">{status}</Badge>;
    }
}

interface SettingsCommandRunsProps {
    runs: PaginatedResponse<CommandRunEntry>;
    manuscriptSchedule: ManuscriptSchedule;
    canManageSchedule: boolean;
    canPublish: boolean;
    canCancel: boolean;
    canRollback: boolean;
    canUnpublish: boolean;
}

export default function SettingsCommandRuns({
    runs,
    manuscriptSchedule,
    canManageSchedule,
    canPublish,
    canCancel,
    canRollback,
    canUnpublish,
}: SettingsCommandRunsProps) {
    const [isLoading, setIsLoading] = useState(false);
    const [previewRun, setPreviewRun] = useState<CommandRunEntry | null>(null);

    useEffect(() => {
        const removeStart = router.on('start', () => setIsLoading(true));
        const removeFinish = router.on('finish', () => setIsLoading(false));

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    function publish(run: CommandRunEntry) {
        router.post(`/settings/command-runs/${run.uuid}/publish`, {}, { preserveScroll: true });
    }

    // Cancel a run permanently stuck at 'queued' (2026-08-27 security-review
    // finding — see App\Http\Controllers\SettingsCommandRunController::
    // cancel()'s doc comment). Lightweight confirm()-gated router.post,
    // matching this app's established pattern for a same-role, no-cooldown
    // destructive action (e.g. Agents/Index.tsx's "Remove agent") rather
    // than a full confirmation modal — this page has no such modal
    // component of its own to reuse, and the action doesn't warrant
    // introducing one.
    function cancel(run: CommandRunEntry) {
        if (!confirm(`Cancel this stuck run for period ${run.period}? This frees that period to be run again.`)) {
            return;
        }
        router.post(`/settings/command-runs/${run.uuid}/cancel`, {}, { preserveScroll: true });
    }

    // Delete/Rollback (2026-08-28 manuscript-run-management addendum) — only
    // ever reachable for the current, unlocked period (run.is_locked gates
    // this row action out entirely below; the backend enforces the same
    // check regardless). Same lightweight confirm()-gated router.post as
    // cancel() above, for the same reason — this page has no confirmation
    // modal of its own to reuse, and this action doesn't warrant introducing
    // one.
    function rollback(run: CommandRunEntry) {
        if (
            !confirm(
                `Delete/roll back this run for period ${run.period}? This permanently removes the manuscript rows it wrote. This cannot be undone — you would need to re-run the calculation.`,
            )
        ) {
            return;
        }
        router.post(`/settings/command-runs/${run.uuid}/rollback`, {}, { preserveScroll: true });
    }

    // Unpublish (2026-08-28 manuscript-run-management addendum) — the clean
    // "undo a publish, fix it, re-generate" path for a live period: unlike
    // Delete/Rollback it also restores the payment/adjustment idempotency
    // stamps this run consumed, so a fresh manuscript:calculate recomputes
    // correct figures with no --force. Same is_locked gating and same
    // lightweight confirm()-gated router.post as the other row actions here.
    function unpublish(run: CommandRunEntry) {
        if (
            !confirm(
                `Unpublish the ${run.period} manuscript? This deletes the manuscript rows this run wrote and frees its payments and adjustments so you can fix and re-run the calculation. The published figures are gone until you re-generate.`,
            )
        ) {
            return;
        }
        router.post(`/settings/command-runs/${run.uuid}/unpublish`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout
            title="Command Runs"
            breadcrumbs={[{ label: 'Settings', href: '/settings/company' }, { label: 'Command Runs' }]}
        >
            <Head title="Settings — Command Runs" />

            <SettingsTabs active="command-runs" />

            <div className="mb-4 flex items-center gap-3 animate-fade-up">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                    <IconTerminal2 size={20} stroke={1.75} />
                </span>
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="font-display text-2xl text-slate-900">Command Runs</h1>
                        {isLoading && <LoadingSpinner className="text-blue-600" />}
                    </div>
                    <p className="text-sm text-slate-500">History of scheduled billing command executions.</p>
                </div>
            </div>

            <Card className="mb-6 animate-fade-up">
                <CardHeader className="flex items-center gap-2">
                    <IconCalendarTime size={18} className="text-slate-500" stroke={1.75} />
                    <h2 className="text-sm font-semibold text-slate-900">Manuscript Generation Schedule</h2>
                </CardHeader>
                <CardBody>
                    {canManageSchedule ? (
                        <Form action="/settings/command-runs/schedule" method="patch">
                            {({ errors, processing, recentlySuccessful }) => (
                                <div className="flex flex-wrap items-end gap-4">
                                    <label className="flex items-center gap-2 pb-2 text-sm font-medium text-slate-700">
                                        {/* Same unchecked-checkbox pattern as Settings/Notifications.tsx —
                                            a hidden "0" sharing the field name, submitted before the real
                                            checkbox, so unchecking is distinguishable from never submitting. */}
                                        <input type="hidden" name="enabled" value="0" />
                                        <input
                                            type="checkbox"
                                            name="enabled"
                                            value="1"
                                            defaultChecked={manuscriptSchedule.enabled}
                                            className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                        />
                                        Enabled
                                    </label>
                                    <div className="w-40">
                                        <SelectInput
                                            id="day_of_month"
                                            name="day_of_month"
                                            label="Run on day of month"
                                            defaultValue={manuscriptSchedule.day_of_month}
                                            error={errors.day_of_month}
                                        >
                                            {Array.from({ length: 31 }, (_, i) => i + 1).map((day) => (
                                                <option key={day} value={day}>
                                                    {day}
                                                </option>
                                            ))}
                                        </SelectInput>
                                    </div>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                        Save Schedule
                                    </Button>
                                    {recentlySuccessful && (
                                        <span className="flex items-center gap-1 pb-2 text-sm font-medium text-emerald-600">
                                            <IconCheck size={16} />
                                            Saved
                                        </span>
                                    )}
                                </div>
                            )}
                        </Form>
                    ) : (
                        <p className="text-sm text-slate-500">
                            Manuscript generation is scheduled to run on day {manuscriptSchedule.day_of_month} of each month
                            ({manuscriptSchedule.enabled ? 'enabled' : 'disabled'}).
                        </p>
                    )}
                    <p className="mt-3 text-xs text-slate-500">
                        A scheduled run computes the full period and pauses for review — see &ldquo;Awaiting Review&rdquo; below —
                        before anything is committed. Months shorter than the chosen day (e.g. day 30 in February) run on that
                        month&apos;s last day instead of being skipped.
                        {manuscriptSchedule.last_run_at && <> Last ran {manuscriptSchedule.last_run_at}.</>}
                    </p>
                </CardBody>
            </Card>

            {runs.data.length === 0 ? (
                <EmptyState
                    title="No command runs recorded"
                    description="History of manuscript:calculate (and other scheduled command) executions will appear here."
                />
            ) : (
                <Card className="p-0 animate-fade-up [animation-delay:100ms]">
                    <Table>
                        <TableHead>
                            <Th>Command</Th>
                            <Th>Period</Th>
                            <Th>Ran At</Th>
                            <Th>Status</Th>
                            <Th>Outcome</Th>
                            <Th>Details</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {runs.data.map((run) => (
                                <tr key={run.uuid} className="transition-colors hover:bg-slate-50/70">
                                    <Td className="font-medium text-slate-900">{run.command}</Td>
                                    <Td>{run.period}</Td>
                                    <Td>{run.ran_at}</Td>
                                    <Td>{statusBadge(run.status, run.batch_progress)}</Td>
                                    <Td>{outcomeBadge(run.metadata)}</Td>
                                    <Td className="text-xs text-slate-500">{metadataSummary(run.metadata)}</Td>
                                    <Td>
                                        <div className="flex items-center gap-2">
                                            {/* Preview is a read-only action — unlike Publish/Cancel/Rollback
                                                below, it stays available for a locked (past-period) run too;
                                                the lock is about mutation, not visibility. */}
                                            {run.computed_result_summary && (
                                                <button
                                                    type="button"
                                                    onClick={() => setPreviewRun(run)}
                                                    className="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700"
                                                >
                                                    <IconEye size={14} stroke={1.75} />
                                                    Preview
                                                </button>
                                            )}

                                            {/* Manuscript-run-management feature (task-scheduler.md's 2026-08-28
                                                addendum): a locked (past-period) run gets a plain read-only
                                                "Locked" badge and NO action menu at all — never a hidden/disabled
                                                menu, per the "never trust a hidden button as the only protection"
                                                rule; the backend enforces this identically regardless of what
                                                renders here. An unlocked run gets one dots-dropdown bundling
                                                whichever of Publish/Cancel/Delete-Rollback actually apply to its
                                                current status — the same Dropdown/DropdownItem/DropdownDivider
                                                kebab-menu pattern already established this session on
                                                Customers/Index.tsx and Disconnections/Index.tsx's Actions columns. */}
                                            {run.is_locked ? (
                                                <Badge tone="slate">
                                                    <IconLock size={12} className="mr-1 inline" stroke={2} />
                                                    Locked
                                                </Badge>
                                            ) : (
                                                (() => {
                                                    const showPublish = run.status === 'pending_review' && canPublish;
                                                    const showCancel = run.status === 'queued' && canCancel;
                                                    const showUnpublish = run.status === 'published' && canUnpublish;
                                                    const showRollback =
                                                        (run.status === 'pending_review' || run.status === 'published' || run.status === 'failed') &&
                                                        canRollback;

                                                    if (!showPublish && !showCancel && !showUnpublish && !showRollback) {
                                                        return null;
                                                    }

                                                    return (
                                                        <Dropdown label={`Actions for ${run.period} run`}>
                                                            {showPublish && (
                                                                <DropdownItem onClick={() => publish(run)} icon={<IconCheck size={16} stroke={1.75} />}>
                                                                    Publish
                                                                </DropdownItem>
                                                            )}
                                                            {showCancel && (
                                                                <DropdownItem
                                                                    onClick={() => cancel(run)}
                                                                    variant="warning"
                                                                    icon={<IconBan size={16} stroke={1.75} />}
                                                                >
                                                                    Cancel
                                                                </DropdownItem>
                                                            )}
                                                            {(showPublish || showCancel) && (showUnpublish || showRollback) && <DropdownDivider />}
                                                            {showUnpublish && (
                                                                <DropdownItem
                                                                    onClick={() => unpublish(run)}
                                                                    variant="warning"
                                                                    icon={<IconArrowBackUp size={16} stroke={1.75} />}
                                                                >
                                                                    Unpublish
                                                                </DropdownItem>
                                                            )}
                                                            {showRollback && (
                                                                <DropdownItem
                                                                    onClick={() => rollback(run)}
                                                                    variant="danger"
                                                                    icon={<IconTrash size={16} stroke={1.75} />}
                                                                >
                                                                    Delete / Rollback
                                                                </DropdownItem>
                                                            )}
                                                        </Dropdown>
                                                    );
                                                })()
                                            )}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={runs.links} />
                    </div>
                </Card>
            )}

            <Modal open={previewRun !== null} onClose={() => setPreviewRun(null)} title={previewRun ? `Preview — ${previewRun.period}` : undefined}>
                {previewRun?.computed_result_summary && (
                    <div className="space-y-3">
                        <p className="text-xs text-slate-500">
                            This is exactly what will be committed to Manuscripts when published — recomputing does not happen at
                            publish time, so these numbers won&apos;t drift even if new payments arrive before then.
                        </p>
                        <ManuscriptRunSummary summary={previewRun.computed_result_summary} />
                        {previewRun.command === 'manuscript:calculate' && (
                            <Link
                                href={`/manuscripts/runs/${previewRun.uuid}`}
                                className="flex items-center justify-between gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2.5 text-sm font-medium text-blue-800 hover:bg-blue-100"
                            >
                                <span>
                                    <IconEye size={15} stroke={1.75} className="mr-1.5 -mt-0.5 inline" />
                                    See the full per-customer breakdown before publishing
                                </span>
                                <span aria-hidden="true">→</span>
                            </Link>
                        )}
                        {previewRun.status === 'pending_review' && canPublish && (
                            <Button
                                type="button"
                                onClick={() => {
                                    publish(previewRun);
                                    setPreviewRun(null);
                                }}
                                className="w-full justify-center"
                            >
                                Publish this period
                            </Button>
                        )}
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}
