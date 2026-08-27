import { FormEvent, ReactNode, useEffect, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { IconAlertTriangle, IconArrowLeft, IconLink, IconLock, IconLockOpen } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ComplaintStatusBadge } from '@/components/shared/StatusBadge';
import { Badge } from '@/components/ui/Badge';
import type { Complaint, ComplaintDuplicateCandidate } from '@/types';

interface ComplaintsShowProps {
    complaint: Complaint;
    can_manage: boolean;
    can_link_duplicate: boolean;
    // The Level 3 human gate (references/complaint-desk.md section 3): true
    // only for super/admin once the complaint has genuinely been open 48h
    // and nobody has clicked the button yet — never rendered based on client
    // logic alone, since ComplaintPolicy::notifyInvestors() and
    // ComplaintEscalationService's 48h business rule are the real gates.
    can_notify_investors: boolean;
    investor_notice_sent_at: string | null;
}

export default function ComplaintsShow({
    complaint,
    can_manage,
    can_link_duplicate,
    can_notify_investors,
    investor_notice_sent_at,
}: ComplaintsShowProps) {
    return (
        <AppLayout title="Complaint Detail" breadcrumbs={[{ label: 'Complaints', href: '/complaints' }, { label: complaint.title }]}>
            <Head title={`Complaint — ${complaint.title}`} />

            <div className="mb-6 animate-fade-up" style={{ animationDelay: '0ms' }}>
                <Link href="/complaints" className="inline-flex items-center gap-1.5 text-sm font-medium text-fuchsia-700 hover:text-fuchsia-800">
                    <IconArrowLeft size={16} stroke={2} />
                    Back to Complaints
                </Link>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <h2 className="font-display text-2xl text-slate-900">{complaint.title}</h2>
                    <ComplaintStatusBadge complaint={complaint} />
                    {complaint.urgent && <Badge tone="red">Urgent</Badge>}
                </div>
                {complaint.duplicate_of_uuid && (
                    <p className="mt-1 text-sm text-slate-500">
                        Linked as a duplicate of{' '}
                        <Link href={`/complaints/${complaint.duplicate_of_uuid}`} className="font-medium text-fuchsia-700 hover:underline">
                            {complaint.duplicate_of_title ?? 'the original complaint'}
                        </Link>
                        .
                    </p>
                )}
            </div>

            <div className="grid max-w-4xl grid-cols-1 gap-4 md:grid-cols-2">
                <Card className="animate-fade-up" style={{ animationDelay: '100ms' }}>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Complaint</h3>
                    </CardHeader>
                    <CardBody className="flex flex-col divide-y divide-slate-100 text-sm">
                        <Row label="Category" value={<span className="capitalize">{complaint.category}</span>} />
                        {complaint.customer_name && <Row label="Customer" value={complaint.customer_name} />}
                        {complaint.zone_name && <Row label="Zone" value={complaint.zone_name} />}
                        <Row label="Submitted by" value={complaint.submitted_by_name ?? '—'} />
                        <Row label="Assigned to" value={complaint.assigned_to_name ?? 'Unassigned'} />
                        <Row label="Submitted" value={new Date(complaint.created_at).toLocaleString()} />
                        <Row label="Description" value={complaint.description || 'No additional detail provided.'} />
                    </CardBody>
                </Card>

                <div className="flex flex-col gap-4">
                    <Card className="animate-fade-up" style={{ animationDelay: '150ms' }}>
                        <CardHeader>
                            <h3 className="text-sm font-semibold text-slate-900">Resolution</h3>
                        </CardHeader>
                        <CardBody className="flex flex-col gap-3 text-sm">
                            {complaint.status === 'resolved' ? (
                                <>
                                    <div className="flex flex-col divide-y divide-slate-100">
                                        <Row label="Resolved by" value={complaint.resolved_by_name ?? '—'} />
                                        <Row label="Resolved at" value={complaint.resolved_at ? new Date(complaint.resolved_at).toLocaleString() : '—'} />
                                        <Row label="Notes" value={complaint.resolution_notes ?? '—'} />
                                    </div>
                                    {can_manage && <ReopenButton complaint={complaint} />}
                                </>
                            ) : can_manage ? (
                                <ResolveForm complaint={complaint} />
                            ) : (
                                <p className="text-slate-500">Not yet resolved.</p>
                            )}
                        </CardBody>
                    </Card>

                    {can_link_duplicate && !complaint.duplicate_of_uuid && complaint.status !== 'resolved' && (
                        <Card className="animate-fade-up" style={{ animationDelay: '200ms' }}>
                            <CardHeader>
                                <h3 className="text-sm font-semibold text-slate-900">Mark as Duplicate</h3>
                            </CardHeader>
                            <CardBody>
                                <LinkDuplicateForm complaint={complaint} />
                            </CardBody>
                        </Card>
                    )}

                    {(can_notify_investors || investor_notice_sent_at) && (
                        <Card className="animate-fade-up border-red-200" style={{ animationDelay: '250ms' }}>
                            <CardHeader>
                                <h3 className="text-sm font-semibold text-slate-900">Investor Notice</h3>
                            </CardHeader>
                            <CardBody>
                                {investor_notice_sent_at ? (
                                    <p className="text-sm text-slate-500">Investors were notified on {new Date(investor_notice_sent_at).toLocaleString()}.</p>
                                ) : (
                                    <NotifyInvestorsButton complaint={complaint} />
                                )}
                            </CardBody>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 py-2 first:pt-0 last:pb-0">
            <span className="shrink-0 text-slate-500">{label}</span>
            <span className="text-right font-medium text-slate-900">{value}</span>
        </div>
    );
}

function ResolveForm({ complaint }: { complaint: Complaint }) {
    const { data, setData, post, processing, errors } = useForm({ resolution_notes: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/complaints/${complaint.uuid}/resolve`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-2">
            <label htmlFor="resolution_notes" className="text-sm font-medium text-slate-700">
                Resolution notes
                <span className="ml-0.5 text-red-500" aria-hidden="true">*</span>
            </label>
            <textarea
                id="resolution_notes"
                rows={3}
                value={data.resolution_notes}
                onChange={(e) => setData('resolution_notes', e.target.value)}
                placeholder="What was done to resolve this?"
                className={`rounded-lg border-0 bg-white px-3.5 py-2 text-sm text-slate-900 shadow-xs ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 ${
                    errors.resolution_notes ? 'ring-red-400' : ''
                }`}
            />
            {errors.resolution_notes && <p className="text-xs text-red-600">{errors.resolution_notes}</p>}
            <Button type="submit" disabled={processing} className="self-start rounded-lg px-4 py-2.5 text-sm font-semibold">
                {processing ? <LoadingSpinner className="h-4 w-4" /> : <IconLock size={16} stroke={2} />}
                {processing ? 'Resolving…' : 'Mark Resolved'}
            </Button>
        </form>
    );
}

function ReopenButton({ complaint }: { complaint: Complaint }) {
    const [processing, setProcessing] = useState(false);

    function reopen() {
        // Plain router visit rather than useForm — this action has no body.
        router.post(
            `/complaints/${complaint.uuid}/reopen`,
            {},
            { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );
    }

    return (
        <div className="border-t border-slate-200 pt-3">
            <p className="mb-2 text-xs text-slate-400">
                Reopening does not reset the clock — a wrongly-resolved complaint reopens already showing its real age.
            </p>
            <Button type="button" variant="secondary" disabled={processing} onClick={reopen} className="rounded-lg px-4 py-2.5 text-sm font-semibold">
                {processing ? <LoadingSpinner className="h-4 w-4" /> : <IconLockOpen size={16} stroke={2} />}
                {processing ? 'Reopening…' : 'Reopen'}
            </Button>
        </div>
    );
}

function NotifyInvestorsButton({ complaint }: { complaint: Complaint }) {
    const [processing, setProcessing] = useState(false);

    function notify() {
        // Plain router visit rather than useForm — this action has no body,
        // same shape as ReopenButton above.
        router.post(
            `/complaints/${complaint.uuid}/notify-investors`,
            {},
            { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );
    }

    return (
        <div className="flex flex-col gap-2">
            <p className="text-xs text-slate-500">
                This complaint has been open 48 hours without resolution. Notifying investors sends an emergency notice now — this cannot be
                undone or automated away, and only happens when you click below.
            </p>
            <Button type="button" variant="danger" disabled={processing} onClick={notify} className="self-start rounded-lg px-4 py-2.5 text-sm font-semibold">
                {processing ? <LoadingSpinner className="h-4 w-4" /> : <IconAlertTriangle size={16} stroke={2} />}
                {processing ? 'Notifying…' : 'Notify Investors'}
            </Button>
        </div>
    );
}

function LinkDuplicateForm({ complaint }: { complaint: Complaint }) {
    const { data, setData, post, processing, errors } = useForm({ duplicate_of_uuid: '' });

    // Reuses GET /complaints/duplicates — the same live-candidate lookup
    // Complaints/Create.tsx's inline duplicate warning already calls — so a
    // manager picks the original from a short list instead of having to
    // paste its UUID/URL from memory. possibleDuplicates() scopes to this
    // complaint's own category (and customer, for a customer complaint;
    // zone, for an operational one) and the last 7 days, so this list is
    // exactly the same "plausible match" pool the submitter already saw a
    // warning about — filtered here to drop this complaint itself, which
    // otherwise always matches its own criteria trivially.
    const [candidates, setCandidates] = useState<ComplaintDuplicateCandidate[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);

        const params = new URLSearchParams({ category: complaint.category });
        if (complaint.category === 'customer' && complaint.customer_uuid) {
            params.set('customer_uuid', complaint.customer_uuid);
        }

        fetch(`/complaints/duplicates?${params.toString()}`, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((body: { complaints: ComplaintDuplicateCandidate[] }) => {
                if (!cancelled) {
                    setCandidates(body.complaints.filter((candidate) => candidate.uuid !== complaint.uuid));
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setCandidates([]);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [complaint.category, complaint.customer_uuid, complaint.uuid]);

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/complaints/${complaint.uuid}/link-duplicate`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            <p className="text-sm text-slate-600">
                Pick the original complaint this one duplicates. It stays fully visible — this only rides on the original&apos;s clock instead of
                running its own.
            </p>

            {loading ? (
                <span className="flex items-center gap-2 text-sm text-slate-500">
                    <LoadingSpinner className="h-4 w-4" />
                    Looking for similar open complaints…
                </span>
            ) : candidates.length === 0 ? (
                <p className="text-sm text-slate-500">
                    No similar open complaints found from the last 7 days
                    {complaint.category === 'customer' ? ' for this customer' : ' in this zone'}.
                </p>
            ) : (
                <div role="radiogroup" aria-label="Original complaint" className="flex flex-col gap-2">
                    {candidates.map((candidate) => (
                        <label
                            key={candidate.uuid}
                            className={`flex cursor-pointer flex-col gap-0.5 rounded-lg border p-3 text-sm transition-colors ${
                                data.duplicate_of_uuid === candidate.uuid
                                    ? 'border-fuchsia-400 bg-fuchsia-50 ring-1 ring-inset ring-fuchsia-300'
                                    : 'border-slate-200 bg-white hover:bg-slate-50'
                            }`}
                        >
                            <input
                                type="radio"
                                name="duplicate_of_uuid"
                                value={candidate.uuid}
                                checked={data.duplicate_of_uuid === candidate.uuid}
                                onChange={() => setData('duplicate_of_uuid', candidate.uuid)}
                                className="sr-only"
                            />
                            <span className="font-medium text-slate-900">{candidate.title}</span>
                            <span className="text-xs text-slate-500">
                                Submitted by {candidate.submitted_by_name ?? 'someone'} on {new Date(candidate.created_at).toLocaleDateString()}
                            </span>
                        </label>
                    ))}
                </div>
            )}

            {errors.duplicate_of_uuid && <p className="text-xs text-red-600">{errors.duplicate_of_uuid}</p>}

            <Button
                type="submit"
                variant="secondary"
                disabled={processing || !data.duplicate_of_uuid}
                className="self-start rounded-lg px-4 py-2.5 text-sm font-semibold"
            >
                {processing ? <LoadingSpinner className="h-4 w-4" /> : <IconLink size={16} stroke={2} />}
                {processing ? 'Linking…' : 'Link as Duplicate'}
            </Button>
        </form>
    );
}
