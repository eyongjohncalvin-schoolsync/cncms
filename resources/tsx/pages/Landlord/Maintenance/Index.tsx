import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { IconTool } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { LandlordMaintenanceWindow } from '@/types';
import { formatCountdown, formatMaintenanceTime, useCountdown } from '@/hooks/useCountdown';

const STATUS_TONE: Record<LandlordMaintenanceWindow['status'], 'yellow' | 'red' | 'green' | 'slate'> = {
    scheduled: 'yellow',
    active: 'red',
    finished: 'green',
    cancelled: 'slate',
};

const DURATIONS = [30, 60, 120, 240];

/** `datetime-local` input value ("2026-10-22T22:00") -> ISO-8601 in UTC. */
function localToIso(value: string): string {
    return value ? new Date(value).toISOString() : '';
}

/** Date -> `datetime-local` input value in the browser's time zone. */
function toLocalInput(date: Date): string {
    const offset = date.getTimezoneOffset() * 60000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function durationLabel(minutes: number): string {
    return minutes < 60 ? `${minutes} min` : `${minutes / 60} h`;
}

/**
 * Landlord scheduling of platform maintenance (App\Http\Controllers\
 * Landlord\MaintenanceWindowController). One live window at a time: while
 * one is scheduled or running this page manages it; otherwise it offers the
 * schedule form.
 */
export default function LandlordMaintenanceIndex({
    windows,
    recipient_count,
}: {
    windows: LandlordMaintenanceWindow[];
    recipient_count: number;
}) {
    const live = windows.find((window) => window.status === 'scheduled' || window.status === 'active') ?? null;

    return (
        <LandlordLayout title="Maintenance" breadcrumbs={[{ label: 'Landlord' }, { label: 'Maintenance' }]}>
            <Head title="Landlord — Maintenance" />

            <div className="animate-fade-up mb-6 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconTool size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">Maintenance mode</h1>
                    <p className="max-w-2xl text-sm text-slate-500">
                        While a window is running every tenant user is held on a countdown page and the mobile app pauses syncing. Landlords keep full access.
                        Users see a countdown banner from the moment a window is scheduled.
                    </p>
                </div>
            </div>

            <div className="animate-fade-up max-w-2xl" style={{ animationDelay: '80ms' }}>
                {live ? <LiveWindow window={live} recipientCount={recipient_count} /> : <ScheduleForm recipientCount={recipient_count} />}
            </div>

            <h2 className="mt-8 mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">History</h2>
            {windows.length === 0 ? (
                <p className="text-sm text-slate-500">No maintenance windows yet.</p>
            ) : (
                <Card>
                    <Table>
                        <TableHead>
                            <tr>
                                <Th>Status</Th>
                                <Th>From</Th>
                                <Th>Until</Th>
                                <Th>Message</Th>
                                <Th>By</Th>
                            </tr>
                        </TableHead>
                        <TableBody>
                            {windows.map((window) => (
                                <tr key={window.id}>
                                    <Td>
                                        <Badge tone={STATUS_TONE[window.status]}>{window.status}</Badge>
                                    </Td>
                                    <Td>{formatMaintenanceTime(window.starts_at)}</Td>
                                    <Td>
                                        {formatMaintenanceTime(window.ended_at ?? window.ends_at)}
                                        {window.ended_at && <span className="ml-1 text-xs text-slate-400">(ended early)</span>}
                                    </Td>
                                    <Td className="max-w-xs truncate">{window.message ?? '—'}</Td>
                                    <Td>{window.created_by ?? '—'}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </LandlordLayout>
    );
}

function NotifyCheckbox({ checked, onChange, recipientCount }: { checked: boolean; onChange: (value: boolean) => void; recipientCount: number }) {
    return (
        <label className="flex items-start gap-2 text-sm text-slate-700">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
            />
            <span>
                Email every tenant user ({recipientCount} with an email address)
                <span className="block text-xs text-slate-400">The in-app banner shows either way.</span>
            </span>
        </label>
    );
}

function ScheduleForm({ recipientCount }: { recipientCount: number }) {
    const [startNow, setStartNow] = useState(false);
    const { data, setData, post, processing, errors, transform, reset } = useForm({
        starts_at: toLocalInput(new Date(Date.now() + 60 * 60000)),
        ends_at: toLocalInput(new Date(Date.now() + 3 * 60 * 60000)),
        message: '',
        notify_by_email: true,
    });

    transform((form) => ({
        ...form,
        starts_at: startNow ? '' : localToIso(form.starts_at),
        ends_at: localToIso(form.ends_at),
    }));

    function setDuration(minutes: number) {
        const start = startNow || !data.starts_at ? new Date() : new Date(data.starts_at);
        setData('ends_at', toLocalInput(new Date(start.getTime() + minutes * 60000)));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/landlord/maintenance', { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <Card>
            <CardHeader>
                <p className="font-medium text-slate-900">Schedule maintenance</p>
            </CardHeader>
            <CardBody>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="flex gap-1" role="group" aria-label="When does it start">
                        {[
                            { label: 'At a set time', value: false },
                            { label: 'Start now', value: true },
                        ].map((option) => (
                            <button
                                key={option.label}
                                type="button"
                                onClick={() => setStartNow(option.value)}
                                aria-pressed={startNow === option.value}
                                className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                    startNow === option.value
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'
                                }`}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>

                    {startNow ? (
                        <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">
                            Tenant users are locked out immediately, without warning. Prefer a set time so staff can finish what they are doing.
                        </p>
                    ) : (
                        <TextInput
                            id="starts_at"
                            type="datetime-local"
                            label="Starts"
                            value={data.starts_at}
                            onChange={(event) => setData('starts_at', event.target.value)}
                            error={errors.starts_at}
                            required
                        />
                    )}

                    <div className="flex flex-col gap-2">
                        <TextInput
                            id="ends_at"
                            type="datetime-local"
                            label="Ends"
                            value={data.ends_at}
                            onChange={(event) => setData('ends_at', event.target.value)}
                            error={errors.ends_at}
                            required
                        />
                        <div className="flex flex-wrap gap-1">
                            {DURATIONS.map((minutes) => (
                                <button
                                    key={minutes}
                                    type="button"
                                    onClick={() => setDuration(minutes)}
                                    className="rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-200"
                                >
                                    {durationLabel(minutes)} after start
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <label htmlFor="message" className="text-sm font-medium text-slate-700">
                            Message to users <span className="font-normal text-slate-400">(optional)</span>
                        </label>
                        <textarea
                            id="message"
                            rows={2}
                            maxLength={500}
                            value={data.message}
                            onChange={(event) => setData('message', event.target.value)}
                            placeholder="e.g. We are moving to the new server."
                            className="w-full rounded-lg border-0 px-3.5 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600"
                        />
                        {errors.message && <p className="text-xs text-red-600">{errors.message}</p>}
                    </div>

                    <NotifyCheckbox checked={data.notify_by_email} onChange={(value) => setData('notify_by_email', value)} recipientCount={recipientCount} />

                    <div className="border-t border-slate-100 pt-4">
                        <Button type="submit" variant={startNow ? 'danger' : 'primary'} disabled={processing}>
                            {processing && <LoadingSpinner className="text-white" />}
                            {startNow ? 'Start maintenance now' : 'Schedule maintenance'}
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}

function LiveWindow({ window, recipientCount }: { window: LandlordMaintenanceWindow; recipientCount: number }) {
    const isActive = window.status === 'active';
    const remaining = useCountdown(isActive ? window.ends_at : window.starts_at);
    const [notify, setNotify] = useState(true);
    const [ending, setEnding] = useState(false);
    const { data, setData, patch, processing, errors, transform } = useForm({
        ends_at: toLocalInput(new Date(window.ends_at)),
        notify_by_email: false,
    });

    transform((form) => ({ ...form, ends_at: localToIso(form.ends_at) }));

    function end() {
        const question = isActive
            ? 'End maintenance now? Tenant users get access back immediately.'
            : 'Cancel this scheduled maintenance?';

        if (!confirm(question)) {
            return;
        }

        setEnding(true);
        router.post(
            `/landlord/maintenance/${window.id}/end`,
            { notify_by_email: !isActive && notify },
            { preserveScroll: true, onFinish: () => setEnding(false) },
        );
    }

    function submitEnd(event: FormEvent) {
        event.preventDefault();
        patch(`/landlord/maintenance/${window.id}`, { preserveScroll: true });
    }

    return (
        <Card className={isActive ? 'ring-2 ring-red-300' : ''}>
            <CardHeader>
                <div className="flex items-center justify-between gap-3">
                    <p className="font-medium text-slate-900">{isActive ? 'Maintenance is running' : 'Maintenance is scheduled'}</p>
                    <Badge tone={STATUS_TONE[window.status]}>{window.status}</Badge>
                </div>
            </CardHeader>
            <CardBody className="flex flex-col gap-5">
                <div className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <p className="text-xs text-slate-500">{isActive ? 'Ends in' : 'Starts in'}</p>
                        <p className="font-display text-3xl tabular-nums text-slate-900">{formatCountdown(remaining)}</p>
                    </div>
                    <div>
                        <p className="text-xs text-slate-500">From</p>
                        <p className="text-sm font-medium text-slate-800">{formatMaintenanceTime(window.starts_at)}</p>
                    </div>
                    <div>
                        <p className="text-xs text-slate-500">Until</p>
                        <p className="text-sm font-medium text-slate-800">{formatMaintenanceTime(window.ends_at)}</p>
                    </div>
                </div>
                {window.message && <p className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">{window.message}</p>}

                <form onSubmit={submitEnd} className="flex flex-col gap-3 border-t border-slate-100 pt-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-56 flex-1">
                            <TextInput
                                id="new_ends_at"
                                type="datetime-local"
                                label="Change end time"
                                value={data.ends_at}
                                onChange={(event) => setData('ends_at', event.target.value)}
                                error={errors.ends_at}
                                required
                            />
                        </div>
                        <Button type="submit" variant="secondary" disabled={processing}>
                            {processing && <LoadingSpinner />}
                            Save end time
                        </Button>
                    </div>
                    <NotifyCheckbox checked={data.notify_by_email} onChange={(value) => setData('notify_by_email', value)} recipientCount={recipientCount} />
                </form>

                <div className="flex flex-col gap-3 border-t border-slate-100 pt-4">
                    {!isActive && <NotifyCheckbox checked={notify} onChange={setNotify} recipientCount={recipientCount} />}
                    <div>
                        <Button type="button" variant={isActive ? 'primary' : 'danger'} onClick={end} disabled={ending}>
                            {ending && <LoadingSpinner className="text-white" />}
                            {isActive ? 'End maintenance now' : 'Cancel maintenance'}
                        </Button>
                    </div>
                </div>
            </CardBody>
        </Card>
    );
}
