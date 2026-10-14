import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { IconAlertTriangle, IconBook2, IconCheck, IconCircleCheck, IconCloudCheck, IconLoader2, IconLock } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { Badge } from '@/components/ui/Badge';
import { formatCurrency } from '@/lib/formatCurrency';
import { xsrfTokenHeader } from '@/lib/csrfToken';

interface Entry {
    arrears: string;
    advance_amount: string;
    advance_months: number;
    note: string | null;
}

interface Row {
    uuid: string;
    name: string;
    zone_name: string | null;
    status: string;
    bill: string;
    imported_balance: string;
    entry: Entry | null;
}

interface Batch {
    uuid: string;
    status: 'draft' | 'pending_approval' | 'approved';
    go_live_period: string;
    baseline_period: string;
    created_by_name: string | null;
    submitted_by_name: string | null;
    submitted_at: string | null;
    approved_by_name: string | null;
    approved_at: string | null;
    rejected_by_name: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    updated_at: string | null;
    is_locked: boolean;
    is_own_submission: boolean;
    can: { edit: boolean; approve: boolean; reject: boolean; reopen: boolean };
}

interface HistoryItem {
    uuid: string;
    go_live_period: string;
    entries_count: number;
    submitted_by_name: string | null;
    approved_by_name: string | null;
    approved_at: string | null;
    is_locked: boolean;
}

interface OpeningBalancesProps {
    batch: Batch | null;
    rows: Row[];
    history: HistoryItem[];
    can_start: boolean;
    month_options: { value: string; label: string }[];
    default_go_live_period: string;
    limits: { max_amount: string; max_advance_months: number };
}

/** What the ledger holds per customer while typing — raw strings, exactly as entered. */
interface Draft {
    arrears: string;
    advance_amount: string;
    advance_months: string;
    note: string;
}

type Column = 'arrears' | 'advance_amount' | 'advance_months' | 'note';
type SaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'error';
type Show = 'all' | 'not_entered' | 'entered' | 'warnings';

const COLUMNS: Column[] = ['arrears', 'advance_amount', 'advance_months', 'note'];
const AUTOSAVE_DELAY_MS = 800;

function monthLabel(period: string): string {
    const [year, month] = period.split('-').map(Number);
    return new Date(year, month - 1, 1).toLocaleDateString('en-GB', {
        month: 'long',
        year: 'numeric',
    });
}

function draftFromEntry(entry: Entry | null): Draft {
    if (!entry) {
        return {
            arrears: '',
            advance_amount: '',
            advance_months: '',
            note: '',
        };
    }

    const amount = (value: string) => (parseFloat(value) === 0 ? '0' : String(parseFloat(value)));

    return {
        arrears: amount(entry.arrears),
        advance_amount: amount(entry.advance_amount),
        advance_months: String(entry.advance_months),
        note: entry.note ?? '',
    };
}

/** "7,500" / "7 500" / "7500 FCFA" → "7500"; blank stays blank. */
function cleanAmount(value: string): string {
    return value.replace(/fcfa/i, '').replace(/[\s,]/g, '').trim();
}

function isBlank(draft: Draft): boolean {
    return draft.arrears === '' && draft.advance_amount === '' && draft.advance_months === '';
}

function num(value: string): number {
    const parsed = parseFloat(cleanAmount(value));
    return Number.isNaN(parsed) ? 0 : parsed;
}

/** Returns the reason a row can't be saved, or null. */
function invalidReason(draft: Draft, maxAmount: number, maxMonths: number): string | null {
    for (const [value, label] of [
        [draft.arrears, 'Owed'],
        [draft.advance_amount, 'Advance'],
    ] as const) {
        const cleaned = cleanAmount(value);
        if (cleaned !== '' && !/^\d+(\.\d{1,2})?$/.test(cleaned)) {
            return `${label} must be a number of FCFA (no minus sign).`;
        }
        if (num(value) > maxAmount) {
            return `${label} is above the ${formatCurrency(maxAmount)} limit — check for an extra zero.`;
        }
    }

    if (draft.advance_months !== '' && !/^\d+$/.test(draft.advance_months.trim())) {
        return 'Months must be a whole number.';
    }
    if (num(draft.advance_months) > maxMonths) {
        return `Months can be at most ${maxMonths}.`;
    }

    return null;
}

/** Gentle, non-blocking checks a clerk should glance at before submitting. */
function warningsFor(draft: Draft, bill: number): string[] {
    const warnings: string[] = [];
    const owed = num(draft.arrears);
    const advance = num(draft.advance_amount);
    const months = num(draft.advance_months);

    if (owed > 0 && (advance > 0 || months > 0)) {
        warnings.push('Both owed and advance are filled in — check this is really both.');
    }
    if (bill > 0 && owed > bill * 24) {
        warnings.push('Owes more than 24 months of bills.');
    }
    if (months > 24) {
        warnings.push('More than 24 months paid ahead.');
    }

    return warnings;
}

export default function OpeningBalancesIndex({ batch, rows, history, can_start, month_options, default_go_live_period, limits }: OpeningBalancesProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const editable = batch?.can.edit ?? false;
    const maxAmount = parseFloat(limits.max_amount);

    const [drafts, setDrafts] = useState<Record<string, Draft>>(() => Object.fromEntries(rows.map((row) => [row.uuid, draftFromEntry(row.entry)])));
    const [dirty, setDirty] = useState<Set<string>>(new Set());
    const [saveState, setSaveState] = useState<SaveState>('idle');
    const [saveError, setSaveError] = useState<string | null>(null);
    const [savedAt, setSavedAt] = useState<string | null>(batch?.updated_at ?? null);

    const [zone, setZone] = useState('');
    const [search, setSearch] = useState('');
    const [show, setShow] = useState<Show>('all');

    const [confirmSubmit, setConfirmSubmit] = useState(false);
    const [confirmApprove, setConfirmApprove] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [rejectionReason, setRejectionReason] = useState('');
    const [busy, setBusy] = useState(false);

    // Re-seed the ledger whenever the server sends a different batch/rows
    // (submit, reject, reopen, approve all reload the page props).
    useEffect(() => {
        setDrafts(Object.fromEntries(rows.map((row) => [row.uuid, draftFromEntry(row.entry)])));
        setDirty(new Set());
        setSaveState('idle');
    }, [rows]);

    // Any status change (submitted, approved, sent back, reopened) closes
    // whichever confirmation dialog triggered it.
    useEffect(() => {
        setConfirmSubmit(false);
        setConfirmApprove(false);
        setRejecting(false);
        setRejectionReason('');
    }, [batch?.uuid, batch?.status]);

    const inputs = useRef<Map<string, HTMLInputElement>>(new Map());
    // Latest drafts, read when a save finishes: a row edited again while its
    // save was in flight must stay dirty so the newer value is sent too.
    const draftsRef = useRef(drafts);
    draftsRef.current = drafts;

    const zones = useMemo(() => [...new Set(rows.map((row) => row.zone_name ?? '—'))].sort(), [rows]);

    const rowWarnings = useMemo(() => Object.fromEntries(rows.map((row) => [row.uuid, warningsFor(drafts[row.uuid] ?? draftFromEntry(null), num(row.bill))])), [rows, drafts]);

    const visibleRows = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return rows.filter((row) => {
            const draft = drafts[row.uuid];
            if (zone && (row.zone_name ?? '—') !== zone) return false;
            if (needle && !row.name.toLowerCase().includes(needle)) return false;
            if (show === 'not_entered' && draft && !isBlank(draft)) return false;
            if (show === 'entered' && (!draft || isBlank(draft))) return false;
            if (show === 'warnings' && (rowWarnings[row.uuid]?.length ?? 0) === 0) return false;
            return true;
        });
    }, [rows, drafts, zone, search, show, rowWarnings]);

    const totals = useMemo(() => {
        const entered = rows.filter((row) => drafts[row.uuid] && !isBlank(drafts[row.uuid]));
        return {
            entered: entered.length,
            owed: entered.reduce((sum, row) => sum + num(drafts[row.uuid].arrears), 0),
            advance: entered.reduce((sum, row) => sum + num(drafts[row.uuid].advance_amount), 0),
            months: entered.reduce((sum, row) => sum + num(drafts[row.uuid].advance_months), 0),
            warnings: rows.filter((row) => (rowWarnings[row.uuid]?.length ?? 0) > 0).length,
        };
    }, [rows, drafts, rowWarnings]);

    const invalidRows = useMemo(
        () => rows.filter((row) => drafts[row.uuid] && invalidReason(drafts[row.uuid], maxAmount, limits.max_advance_months) !== null),
        [rows, drafts, maxAmount, limits.max_advance_months],
    );

    // ---- Autosave ----------------------------------------------------------
    const save = useCallback(async () => {
        if (!batch || dirty.size === 0) return;

        const uuids = [...dirty].filter((uuid) => invalidReason(drafts[uuid], maxAmount, limits.max_advance_months) === null);
        if (uuids.length === 0) {
            setSaveState('error');
            setSaveError('Fix the highlighted rows — they are not saved yet.');
            return;
        }

        setSaveState('saving');
        const sent = Object.fromEntries(uuids.map((uuid) => [uuid, drafts[uuid]]));
        const payload = uuids.map((uuid) => {
            const draft = drafts[uuid];
            const blank = isBlank(draft);
            return {
                customer_uuid: uuid,
                arrears: blank ? null : cleanAmount(draft.arrears) || null,
                advance_amount: blank ? null : cleanAmount(draft.advance_amount) || null,
                advance_months: blank || draft.advance_months.trim() === '' ? null : Number(draft.advance_months),
                note: draft.note.trim() || null,
            };
        });

        try {
            const response = await fetch(`/opening-balances/${batch.uuid}/entries`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...xsrfTokenHeader(),
                },
                body: JSON.stringify({ rows: payload }),
            });

            if (!response.ok) {
                const body = await response.json().catch(() => null);
                const message = body?.errors ? Object.values(body.errors as Record<string, string[]>).flat()[0] : body?.message;
                throw new Error(message ?? `Saving failed (${response.status}).`);
            }

            const body = (await response.json()) as { saved_at: string };
            setDirty((current) => {
                const next = new Set(current);
                uuids.forEach((uuid) => {
                    if (draftsRef.current[uuid] === sent[uuid]) {
                        next.delete(uuid);
                    }
                });
                return next;
            });
            setSavedAt(body.saved_at);
            setSaveError(null);
            setSaveState('saved');
        } catch (error) {
            setSaveError((error as Error).message);
            setSaveState('error');
        }
    }, [batch, dirty, drafts, maxAmount, limits.max_advance_months]);

    useEffect(() => {
        if (dirty.size === 0 || saveState === 'saving') return;
        const timer = window.setTimeout(save, AUTOSAVE_DELAY_MS);
        return () => window.clearTimeout(timer);
    }, [dirty, drafts, save, saveState]);

    // Warn before leaving with unsaved typing.
    useEffect(() => {
        if (dirty.size === 0) return;
        const handler = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [dirty]);

    function setField(uuid: string, column: Column, value: string) {
        setDrafts((current) => ({
            ...current,
            [uuid]: { ...current[uuid], [column]: value },
        }));
        setDirty((current) => new Set(current).add(uuid));
        setSaveState('pending');
    }

    function markNothingOwed(uuid: string) {
        setDrafts((current) => ({
            ...current,
            [uuid]: {
                ...current[uuid],
                arrears: '0',
                advance_amount: '0',
                advance_months: '0',
            },
        }));
        setDirty((current) => new Set(current).add(uuid));
        setSaveState('pending');
    }

    function clearRow(uuid: string) {
        setDrafts((current) => ({
            ...current,
            [uuid]: {
                arrears: '',
                advance_amount: '',
                advance_months: '',
                note: '',
            },
        }));
        setDirty((current) => new Set(current).add(uuid));
        setSaveState('pending');
    }

    /** Enter / ↓ moves down the same column, ↑ moves up — like working down a paper ledger. */
    function onCellKeyDown(event: KeyboardEvent<HTMLInputElement>, index: number, column: Column) {
        const step = event.key === 'Enter' || event.key === 'ArrowDown' ? (event.shiftKey ? -1 : 1) : event.key === 'ArrowUp' ? -1 : 0;
        if (step === 0) return;

        event.preventDefault();
        const target = visibleRows[index + step];
        if (target) {
            const input = inputs.current.get(`${target.uuid}:${column}`);
            input?.focus();
            input?.select();
        }
    }

    // ---- Workflow actions ---------------------------------------------------
    function post(url: string, data: Record<string, string> = {}, onSuccess?: () => void) {
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess,
        });
    }

    function startBatch(goLive: string) {
        post('/opening-balances', { go_live_period: goLive });
    }

    function changeGoLive(goLive: string) {
        if (!batch) return;
        router.patch(`/opening-balances/${batch.uuid}`, { go_live_period: goLive }, { preserveScroll: true });
    }

    function discard() {
        if (!batch) return;
        if (window.confirm('Discard this draft? Every balance typed so far will be deleted.')) {
            router.delete(`/opening-balances/${batch.uuid}`);
        }
    }

    function reopen() {
        if (!batch) return;
        if (window.confirm('Reopen these approved balances for editing? The starting positions are removed until it is approved again.')) {
            post(`/opening-balances/${batch.uuid}/reopen`);
        }
    }

    const unsavedInvalid = [...dirty].filter((uuid) => invalidReason(drafts[uuid], maxAmount, limits.max_advance_months) !== null).length;

    const savingIndicator = (() => {
        if (!editable) return null;
        if (unsavedInvalid > 0) {
            return (
                <span className="inline-flex items-center gap-1.5 text-sm text-red-700">
                    <IconAlertTriangle size={16} /> {unsavedInvalid} row(s) not saved — fix the values highlighted in red.
                </span>
            );
        }
        if (saveState === 'saving' || saveState === 'pending') {
            return (
                <span className="inline-flex items-center gap-1.5 text-sm text-slate-500">
                    <IconLoader2 size={16} className="animate-spin" /> Saving…
                </span>
            );
        }
        if (saveState === 'error') {
            return (
                <span className="inline-flex items-center gap-1.5 text-sm text-red-700">
                    <IconAlertTriangle size={16} /> {saveError}{' '}
                    <button type="button" onClick={save} className="font-semibold underline">
                        Retry
                    </button>
                </span>
            );
        }
        return (
            <span className="inline-flex items-center gap-1.5 text-sm text-green-700">
                <IconCloudCheck size={16} /> All changes saved
                {savedAt ? ` · ${new Date(savedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : ''}
            </span>
        );
    })();

    return (
        <AppLayout title="Opening balances">
            <Head title="Opening balances" />

            <div className="animate-fade-up mb-4 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-teal-100 text-teal-700">
                    <IconBook2 size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Opening balances</h1>
                    <p className="text-sm text-slate-500">Copy each customer's starting position from the paper records: what they owe and anything paid in advance.</p>
                </div>
            </div>

            {Object.keys(errors).length > 0 && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {Object.values(errors).map((message) => (
                        <p key={message}>{message}</p>
                    ))}
                </div>
            )}

            {!batch && <StartCard canStart={can_start} monthOptions={month_options} defaultGoLive={default_go_live_period} onStart={startBatch} busy={busy} />}

            {batch && (
                <>
                    <StatusBanner batch={batch} onReopen={reopen} busy={busy} />

                    <div className="animate-fade-up mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <StatCard label="Customers entered" value={`${totals.entered} of ${rows.length}`} tone="blue" />
                        <StatCard label="Total owed" value={formatCurrency(totals.owed)} tone="red" />
                        <StatCard
                            label="Total advance"
                            value={formatCurrency(totals.advance)}
                            hint={totals.months > 0 ? `+ ${totals.months} prepaid month(s)` : undefined}
                            tone="green"
                        />
                        <StatCard label="Rows to double-check" value={String(totals.warnings)} tone={totals.warnings > 0 ? 'yellow' : 'slate'} />
                    </div>

                    <Card className="animate-fade-up mb-4 p-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="w-48">
                                <SelectInput label="First billing month" value={batch.go_live_period} disabled={!editable} onChange={(event) => changeGoLive(event.target.value)}>
                                    {month_options.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            </div>
                            <div className="w-48">
                                <SelectInput label="Zone" value={zone} onChange={(event) => setZone(event.target.value)}>
                                    <option value="">All zones</option>
                                    {zones.map((name) => (
                                        <option key={name} value={name}>
                                            {name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </div>
                            <div className="w-44">
                                <SelectInput label="Show" value={show} onChange={(event) => setShow(event.target.value as Show)}>
                                    <option value="all">Everyone</option>
                                    <option value="not_entered">Not entered yet</option>
                                    <option value="entered">Entered</option>
                                    <option value="warnings">To double-check</option>
                                </SelectInput>
                            </div>
                            <div className="min-w-48 flex-1">
                                <TextInput label="Search" placeholder="Customer name" value={search} onChange={(event) => setSearch(event.target.value)} />
                            </div>
                            <div className="ml-auto flex items-center gap-3">{savingIndicator}</div>
                        </div>
                        <p className="mt-3 text-xs text-slate-500">
                            "Owed" is what the customer owed <strong>before {monthLabel(batch.go_live_period)}</strong>; that month's bill is added by the first bill run. Enter an
                            advance as money, as months paid ahead (charged at the customer's current monthly bill), or both. Press <kbd className="rounded border px-1">Enter</kbd>{' '}
                            to move down a column.
                        </p>
                    </Card>

                    {rows.length === 0 ? (
                        <EmptyState title="No customers need an opening balance" description="Every customer already has billing history. Import or add customers first." />
                    ) : (
                        <LedgerTable
                            rows={visibleRows}
                            drafts={drafts}
                            editable={editable}
                            goLiveLabel={monthLabel(batch.go_live_period)}
                            rowWarnings={rowWarnings}
                            invalid={(uuid) => (drafts[uuid] ? invalidReason(drafts[uuid], maxAmount, limits.max_advance_months) : null)}
                            onChange={setField}
                            onKeyDown={onCellKeyDown}
                            onNothingOwed={markNothingOwed}
                            onClear={clearRow}
                            registerInput={(key, element) => {
                                if (element) inputs.current.set(key, element);
                                else inputs.current.delete(key);
                            }}
                        />
                    )}

                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            {editable && (
                                <Button variant="ghost" onClick={discard} disabled={busy}>
                                    Discard draft
                                </Button>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {editable && (
                                <Button
                                    onClick={() => setConfirmSubmit(true)}
                                    disabled={busy || totals.entered === 0 || dirty.size > 0 || invalidRows.length > 0}
                                    title={dirty.size > 0 ? 'Wait for your changes to save' : undefined}
                                >
                                    Submit for approval
                                </Button>
                            )}
                            {batch.can.reject && (
                                <Button variant="secondary" onClick={() => setRejecting(true)} disabled={busy}>
                                    Send back for correction
                                </Button>
                            )}
                            {batch.can.approve && (
                                <Button onClick={() => setConfirmApprove(true)} disabled={busy}>
                                    <IconCheck size={16} /> Approve opening balances
                                </Button>
                            )}
                        </div>
                    </div>

                    <Modal open={confirmSubmit} onClose={() => setConfirmSubmit(false)} title="Submit for approval?">
                        <Summary totals={totals} goLive={monthLabel(batch.go_live_period)} customerCount={rows.length} />
                        <p className="mt-3 text-sm text-slate-600">After submitting you can't edit until the approver approves it or sends it back.</p>
                        <div className="mt-4 flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setConfirmSubmit(false)}>
                                Keep editing
                            </Button>
                            <Button onClick={() => post(`/opening-balances/${batch.uuid}/submit`, {}, () => setConfirmSubmit(false))} disabled={busy}>
                                Submit
                            </Button>
                        </div>
                    </Modal>

                    <Modal open={confirmApprove} onClose={() => setConfirmApprove(false)} title="Approve opening balances?">
                        <Summary totals={totals} goLive={monthLabel(batch.go_live_period)} customerCount={rows.length} />
                        {batch.is_own_submission && (
                            <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">You submitted this batch yourself — you're approving your own entries.</p>
                        )}
                        <p className="mt-3 text-sm text-slate-600">
                            These become each customer's starting position. The first bill run for {monthLabel(batch.go_live_period)} carries them forward.
                        </p>
                        <div className="mt-4 flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setConfirmApprove(false)}>
                                Cancel
                            </Button>
                            <Button onClick={() => post(`/opening-balances/${batch.uuid}/approve`, {}, () => setConfirmApprove(false))} disabled={busy}>
                                Approve
                            </Button>
                        </div>
                    </Modal>

                    <Modal open={rejecting} onClose={() => setRejecting(false)} title="Send back for correction">
                        <TextInput
                            label="What needs fixing?"
                            value={rejectionReason}
                            onChange={(event) => setRejectionReason(event.target.value)}
                            placeholder="e.g. Zone AR02 totals don't match the ledger book"
                            error={errors.rejection_reason}
                        />
                        <div className="mt-4 flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setRejecting(false)}>
                                Cancel
                            </Button>
                            <Button
                                variant="warning"
                                onClick={() => post(`/opening-balances/${batch.uuid}/reject`, { rejection_reason: rejectionReason }, () => setRejecting(false))}
                                disabled={busy || rejectionReason.trim() === ''}
                            >
                                Send back
                            </Button>
                        </div>
                    </Modal>
                </>
            )}

            {history.length > 0 && (
                <Card className="animate-fade-up mt-6 p-4">
                    <h2 className="mb-2 font-semibold text-slate-800">Approved opening balances</h2>
                    <ul className="divide-y divide-slate-100 text-sm">
                        {history.map((item) => (
                            <li key={item.uuid} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>
                                    First billing month <strong>{monthLabel(item.go_live_period)}</strong> · {item.entries_count} customer(s) · entered by{' '}
                                    {item.submitted_by_name ?? '—'}, approved by {item.approved_by_name ?? '—'}
                                </span>
                                <span className="flex items-center gap-2">
                                    {item.is_locked && (
                                        <Badge tone="slate">
                                            <IconLock size={12} className="mr-1 inline" /> Locked by first bill run
                                        </Badge>
                                    )}
                                    <Link href={`/opening-balances?batch=${item.uuid}`} className="font-medium text-blue-700 hover:underline">
                                        View
                                    </Link>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </AppLayout>
    );
}

function StartCard({
    canStart,
    monthOptions,
    defaultGoLive,
    onStart,
    busy,
}: {
    canStart: boolean;
    monthOptions: { value: string; label: string }[];
    defaultGoLive: string;
    onStart: (goLive: string) => void;
    busy: boolean;
}) {
    const [goLive, setGoLive] = useState(defaultGoLive);

    return (
        <Card className="animate-fade-up p-6">
            <h2 className="font-semibold text-slate-800">Bring in balances from paper records</h2>
            <ol className="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
                <li>Pick the first month CNCMS will bill.</li>
                <li>Type each customer's balance from the ledger book — work zone by zone; everything saves as you go.</li>
                <li>Submit. The owner (or someone they authorise) checks and approves it.</li>
                <li>The first bill run carries the balances forward. After that, corrections go through arrears adjustments.</li>
            </ol>
            {canStart ? (
                <div className="mt-4 flex flex-wrap items-end gap-3">
                    <div className="w-56">
                        <SelectInput label="First billing month" value={goLive} onChange={(event) => setGoLive(event.target.value)}>
                            {monthOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <Button onClick={() => onStart(goLive)} disabled={busy}>
                        Start entering balances
                    </Button>
                </div>
            ) : (
                <p className="mt-4 text-sm text-slate-500">There is no batch in progress. Someone with permission to enter opening balances can start one.</p>
            )}
        </Card>
    );
}

function StatusBanner({ batch, onReopen, busy }: { batch: Batch; onReopen: () => void; busy: boolean }) {
    const when = (iso: string | null) =>
        iso
            ? new Date(iso).toLocaleString([], {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              })
            : '';

    if (batch.status === 'draft') {
        return (
            <div className="animate-fade-up mb-4 space-y-2">
                {batch.rejection_reason && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <strong>Sent back by {batch.rejected_by_name ?? 'the approver'}</strong> ({when(batch.rejected_at)}): {batch.rejection_reason}
                    </div>
                )}
                <div className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                    <strong>Draft</strong> — started by {batch.created_by_name ?? '—'}. Nothing affects billing until it is submitted and approved.
                </div>
            </div>
        );
    }

    if (batch.status === 'pending_approval') {
        return (
            <div className="animate-fade-up mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <strong>Awaiting approval</strong> — submitted by {batch.submitted_by_name ?? '—'} on {when(batch.submitted_at)}. Read-only until it is approved or sent back.
            </div>
        );
    }

    return (
        <div className="animate-fade-up mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
            <span className="inline-flex items-center gap-2">
                <IconCircleCheck size={18} />
                <span>
                    <strong>Approved</strong> by {batch.approved_by_name ?? '—'} on {when(batch.approved_at)} · entered by {batch.submitted_by_name ?? '—'}.{' '}
                    {batch.is_locked ? 'Locked — the first bill run has used these balances; correct a customer with an arrears adjustment.' : 'Not used by a bill run yet.'}
                </span>
            </span>
            {batch.can.reopen && !batch.is_locked && (
                <Button variant="secondary" onClick={onReopen} disabled={busy}>
                    Reopen for editing
                </Button>
            )}
        </div>
    );
}

function Summary({
    totals,
    goLive,
    customerCount,
}: {
    totals: {
        entered: number;
        owed: number;
        advance: number;
        months: number;
        warnings: number;
    };
    goLive: string;
    customerCount: number;
}) {
    return (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
            <dt className="text-slate-500">First billing month</dt>
            <dd className="font-medium">{goLive}</dd>
            <dt className="text-slate-500">Customers entered</dt>
            <dd className="font-medium">
                {totals.entered} of {customerCount}
            </dd>
            <dt className="text-slate-500">Total owed</dt>
            <dd className="font-medium">{formatCurrency(totals.owed)}</dd>
            <dt className="text-slate-500">Total advance</dt>
            <dd className="font-medium">
                {formatCurrency(totals.advance)}
                {totals.months > 0 ? ` + ${totals.months} prepaid month(s)` : ''}
            </dd>
            {totals.warnings > 0 && (
                <>
                    <dt className="text-amber-700">Rows to double-check</dt>
                    <dd className="font-medium text-amber-700">{totals.warnings}</dd>
                </>
            )}
        </dl>
    );
}

function LedgerTable({
    rows,
    drafts,
    editable,
    goLiveLabel,
    rowWarnings,
    invalid,
    onChange,
    onKeyDown,
    onNothingOwed,
    onClear,
    registerInput,
}: {
    rows: Row[];
    drafts: Record<string, Draft>;
    editable: boolean;
    goLiveLabel: string;
    rowWarnings: Record<string, string[]>;
    invalid: (uuid: string) => string | null;
    onChange: (uuid: string, column: Column, value: string) => void;
    onKeyDown: (event: KeyboardEvent<HTMLInputElement>, index: number, column: Column) => void;
    onNothingOwed: (uuid: string) => void;
    onClear: (uuid: string) => void;
    registerInput: (key: string, element: HTMLInputElement | null) => void;
}) {
    const cellClass = (bad: boolean) =>
        `w-full rounded-md border px-2 py-1.5 text-right tabular-nums focus:outline-none focus:ring-2 ${
            bad ? 'border-red-400 bg-red-50 focus:ring-red-300' : 'border-slate-300 focus:border-blue-500 focus:ring-blue-200'
        } disabled:border-transparent disabled:bg-transparent`;

    if (rows.length === 0) {
        return <EmptyState title="No customers match these filters" />;
    }

    return (
        <div className="max-h-[70vh] w-full overflow-auto rounded-lg border border-slate-200 bg-white">
            <table className="min-w-full text-sm">
                <thead className="sticky top-0 z-10 bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase shadow-sm">
                    <tr>
                        <th className="px-3 py-2">Customer</th>
                        <th className="px-3 py-2">Zone</th>
                        <th className="px-3 py-2 text-right">Monthly bill</th>
                        <th className="w-36 px-3 py-2 text-right">Owed before {goLiveLabel}</th>
                        <th className="w-32 px-3 py-2 text-right">Advance (FCFA)</th>
                        <th className="w-28 px-3 py-2 text-right">Months paid ahead</th>
                        <th className="w-48 px-3 py-2">Note</th>
                        {editable && <th className="px-3 py-2" />}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.map((row, index) => {
                        const draft = drafts[row.uuid];
                        const problem = invalid(row.uuid);
                        const warnings = rowWarnings[row.uuid] ?? [];
                        const entered = draft && !isBlank(draft);

                        return (
                            <tr key={row.uuid} className={problem ? 'bg-red-50/50' : warnings.length > 0 ? 'bg-amber-50/50' : entered ? '' : 'bg-slate-50/40'}>
                                <td className="px-3 py-1.5">
                                    <div className="flex items-center gap-1.5 font-medium text-slate-800">
                                        {entered && !problem && <IconCheck size={14} className="text-green-600" aria-label="entered" />}
                                        {row.name}
                                    </div>
                                    {row.status !== 'active' && <span className="text-xs text-slate-500 capitalize">{row.status}</span>}
                                    {problem && <p className="text-xs text-red-700">{problem}</p>}
                                    {!problem &&
                                        warnings.map((warning) => (
                                            <p key={warning} className="text-xs text-amber-700">
                                                {warning}
                                            </p>
                                        ))}
                                    {parseFloat(row.imported_balance) > 0 && !entered && (
                                        <p className="text-xs text-slate-500">Imported balance: {formatCurrency(row.imported_balance)}</p>
                                    )}
                                </td>
                                <td className="px-3 py-1.5 whitespace-nowrap text-slate-600">{row.zone_name ?? '—'}</td>
                                <td className="px-3 py-1.5 text-right whitespace-nowrap text-slate-600 tabular-nums">{formatCurrency(row.bill)}</td>
                                {COLUMNS.map((column) => (
                                    <td key={column} className="px-2 py-1.5">
                                        <input
                                            ref={(element) => registerInput(`${row.uuid}:${column}`, element)}
                                            value={draft?.[column] ?? ''}
                                            disabled={!editable}
                                            inputMode={column === 'note' ? 'text' : column === 'advance_months' ? 'numeric' : 'decimal'}
                                            placeholder={editable && column !== 'note' ? '—' : ''}
                                            aria-label={`${row.name} ${column.replace('_', ' ')}`}
                                            onChange={(event) => onChange(row.uuid, column, event.target.value)}
                                            onKeyDown={(event) => onKeyDown(event, index, column)}
                                            onFocus={(event) => event.target.select()}
                                            className={column === 'note' ? cellClass(false).replace('text-right tabular-nums', 'text-left') : cellClass(problem !== null)}
                                        />
                                    </td>
                                ))}
                                {editable && (
                                    <td className="px-3 py-1.5 whitespace-nowrap">
                                        {entered ? (
                                            <button type="button" onClick={() => onClear(row.uuid)} className="text-xs text-slate-500 hover:text-slate-800 hover:underline">
                                                Clear
                                            </button>
                                        ) : (
                                            <button type="button" onClick={() => onNothingOwed(row.uuid)} className="text-xs font-medium text-blue-700 hover:underline">
                                                Nothing owed
                                            </button>
                                        )}
                                    </td>
                                )}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
