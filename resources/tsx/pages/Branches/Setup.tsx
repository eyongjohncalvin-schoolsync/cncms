import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type KeyboardEvent, type ReactNode } from 'react';
import { IconCircleCheck, IconCircleDashed, IconX } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { InfoTip } from '@/components/ui/InfoTip';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { Branch } from '@/types';

interface SetupChecklist {
    zones: { done: boolean; count: number; names: string[] };
    manager: { done: boolean; names: string[] };
    /** Not applicable to headquarters, whose values are the company settings. */
    settings: { applicable: boolean; done: boolean };
}

interface BranchesSetupProps {
    branch: Branch;
    checklist: SetupChecklist;
    can: { add_zones: boolean; manage_users: boolean; manage_settings: boolean };
    max_names: number;
}

/** How many existing zone names to list before "and N more". */
const SHOWN_ZONES = 30;

/** Same clean-up the server applies: trimmed, single spaces, capitals. */
const normalise = (value: string) => value.replace(/\s+/g, ' ').trim().toUpperCase();

const linkButton =
    'inline-flex items-center justify-center rounded-lg bg-white px-3.5 py-2 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50 hover:ring-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400';

function ChecklistStep({ done, title, children, action }: { done: boolean | null; title: string; children: ReactNode; action?: ReactNode }) {
    return (
        <li className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex min-w-0 gap-3">
                {done ? (
                    <IconCircleCheck size={22} stroke={1.75} className="shrink-0 text-emerald-600" aria-hidden="true" />
                ) : (
                    <IconCircleDashed size={22} stroke={1.75} className="shrink-0 text-slate-400" aria-hidden="true" />
                )}
                <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-900">
                        {title}
                        <span className="sr-only">{done === null ? ' (not needed)' : done ? ' (done)' : ' (not done yet)'}</span>
                    </p>
                    <div className="mt-0.5 text-sm text-slate-500">{children}</div>
                </div>
            </div>
            {action && <div className="shrink-0 sm:ml-4">{action}</div>}
        </li>
    );
}

/**
 * Shown straight after a branch is created (and any time later from the
 * branch list): quick-add its zones, then a checklist of what the branch
 * still needs. Every tick is computed server-side
 * (App\Services\BranchService::setupChecklist()).
 */
export default function BranchesSetup({ branch, checklist, can, max_names }: BranchesSetupProps) {
    const [draft, setDraft] = useState('');
    const [draftError, setDraftError] = useState<string | null>(null);
    const form = useForm<{ names: string[] }>({ names: [] });
    const errors = form.errors as Record<string, string | undefined>;
    const names = form.data.names;

    function addDraft() {
        const additions = draft.split(',').map(normalise).filter((name) => name !== '');

        if (additions.length === 0) {
            setDraftError('Type a zone name first.');

            return;
        }

        const next = [...names];
        const repeated: string[] = [];

        for (const name of additions) {
            if (next.includes(name)) {
                repeated.push(name);
            } else if (next.length < max_names) {
                next.push(name);
            }
        }

        form.clearErrors();
        form.setData('names', next);
        setDraft('');
        setDraftError(repeated.length > 0 ? `${repeated.join(', ')} is already in the list.` : null);
    }

    function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Enter') {
            event.preventDefault();
            addDraft();
        }
    }

    function remove(index: number) {
        form.clearErrors();
        form.setData(
            'names',
            names.filter((_, i) => i !== index),
        );
    }

    function save(event: FormEvent) {
        event.preventDefault();
        form.post(`/branches/${branch.uuid}/zones`, { preserveScroll: true, onSuccess: () => form.reset() });
    }

    const hasZones = checklist.zones.count > 0;
    const hiddenZones = checklist.zones.names.length - SHOWN_ZONES;

    return (
        <AppLayout
            title={`Set up ${branch.name}`}
            breadcrumbs={[{ label: 'Branches', href: '/branches' }, { label: branch.name }, { label: 'Set up' }]}
        >
            <Head title={`Set up ${branch.name}`} />

            <div className="animate-fade-up mb-6 flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <h2 className="font-display text-2xl text-slate-900">
                        {hasZones ? `Set up ${branch.name}` : `${branch.name} is ready — add its zones now?`}
                    </h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-500">
                        A few quick steps get this branch ready for its agents. You can come back to this page any time from
                        the branch list.
                    </p>
                </div>
                <Link href="/branches" className="text-sm font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline">
                    Skip for now
                </Link>
            </div>

            <div className="grid max-w-4xl gap-6">
                {can.add_zones && (
                    <Card className="animate-fade-up" id="quick-add">
                        <CardHeader>
                            <div className="flex items-center gap-1">
                                <h3 className="text-sm font-semibold text-slate-900">Add zones</h3>
                                <InfoTip
                                    label="About adding zones"
                                    text="Zones are the areas your agents collect in. Each zone belongs to one branch."
                                />
                            </div>
                        </CardHeader>
                        <CardBody>
                            <form onSubmit={save} className="flex flex-col gap-4">
                                <div className="flex flex-col gap-1.5">
                                    <div className="flex items-center gap-1">
                                        <label htmlFor="zone-draft" className="text-sm font-medium text-slate-700">
                                            Zone name
                                        </label>
                                        <InfoTip
                                            label="About the zone name"
                                            text="Type one name and press Enter. Add as many as you need, then save them all at once."
                                        />
                                    </div>
                                    <div className="flex flex-col gap-2 sm:flex-row">
                                        <input
                                            id="zone-draft"
                                            value={draft}
                                            onChange={(e) => {
                                                setDraft(e.target.value);
                                                setDraftError(null);
                                            }}
                                            onKeyDown={onKeyDown}
                                            maxLength={25}
                                            autoCapitalize="characters"
                                            autoComplete="off"
                                            placeholder="e.g. MOLYKO"
                                            aria-describedby={draftError ? 'zone-draft-error' : undefined}
                                            className="w-full rounded-lg border-0 bg-white px-3.5 py-2.5 text-base text-slate-900 uppercase shadow-xs ring-1 ring-inset ring-slate-300 transition-shadow duration-150 placeholder:text-slate-400 placeholder:normal-case hover:ring-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:py-2 sm:text-sm"
                                        />
                                        <Button type="button" variant="secondary" onClick={addDraft} className="shrink-0 rounded-lg px-4">
                                            Add
                                        </Button>
                                    </div>
                                    {draftError && (
                                        <p id="zone-draft-error" className="text-xs text-red-600">
                                            {draftError}
                                        </p>
                                    )}
                                    {errors.names && <p className="text-xs text-red-600">{errors.names}</p>}
                                </div>

                                {names.length > 0 && (
                                    <ul className="flex flex-wrap gap-2" aria-label="Zones to save">
                                        {names.map((name, index) => {
                                            const error = errors[`names.${index}`];

                                            return (
                                                <li key={name} className="flex max-w-full flex-col">
                                                    <span
                                                        className={`inline-flex max-w-full items-center gap-1 rounded-full py-1 pr-1 pl-3 text-sm font-medium ring-1 ring-inset ${
                                                            error ? 'bg-red-50 text-red-800 ring-red-300' : 'bg-blue-50 text-blue-800 ring-blue-200'
                                                        }`}
                                                    >
                                                        <span className="truncate">{name}</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => remove(index)}
                                                            aria-label={`Remove ${name}`}
                                                            className="inline-flex size-6 shrink-0 items-center justify-center rounded-full hover:bg-black/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600"
                                                        >
                                                            <IconX size={14} stroke={2} aria-hidden="true" />
                                                        </button>
                                                    </span>
                                                    {error && <span className="mt-1 px-1 text-xs text-red-600">{error}</span>}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}

                                <div className="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                                    <Button type="submit" disabled={form.processing || names.length === 0} className="rounded-lg px-5 py-2.5 text-sm font-semibold">
                                        {form.processing && <LoadingSpinner className="text-white" />}
                                        {form.processing
                                            ? 'Saving…'
                                            : names.length === 0
                                              ? 'Save zones'
                                              : `Save ${names.length} ${names.length === 1 ? 'zone' : 'zones'}`}
                                    </Button>
                                    {names.length >= max_names && <p className="text-xs text-slate-500">Up to {max_names} zones at a time.</p>}
                                </div>
                            </form>
                        </CardBody>
                    </Card>
                )}

                <Card className="animate-fade-up" style={{ animationDelay: '0.05s' }}>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Setup checklist</h3>
                    </CardHeader>
                    <CardBody>
                        <ol className="divide-y divide-slate-100">
                            <ChecklistStep
                                done={checklist.zones.done}
                                title="Zones added"
                                action={
                                    can.add_zones ? (
                                        <Link href={`/zones/create?branch=${branch.uuid}`} className={linkButton}>
                                            Add one zone
                                        </Link>
                                    ) : undefined
                                }
                            >
                                {hasZones ? (
                                    <>
                                        {checklist.zones.count === 1 ? '1 zone' : `${checklist.zones.count} zones`}:{' '}
                                        {checklist.zones.names.slice(0, SHOWN_ZONES).join(', ')}
                                        {hiddenZones > 0 && ` and ${hiddenZones} more`}
                                    </>
                                ) : (
                                    'No zones yet. Agents and customers are placed in zones.'
                                )}
                            </ChecklistStep>

                            <ChecklistStep
                                done={checklist.manager.done}
                                title="Branch manager assigned"
                                action={
                                    can.manage_users ? (
                                        <Link href="/users" className={linkButton}>
                                            Open Users
                                        </Link>
                                    ) : undefined
                                }
                            >
                                {checklist.manager.done
                                    ? `Run by ${checklist.manager.names.join(', ')}.`
                                    : 'Nobody runs this branch yet. On the Users page, give a manager or admin this branch.'}
                            </ChecklistStep>

                            {checklist.settings.applicable ? (
                                <ChecklistStep
                                    done={checklist.settings.done}
                                    title="Branch details, fees and prices"
                                    action={
                                        can.manage_settings ? (
                                            <Link href={`/branches/${branch.uuid}/settings`} className={linkButton}>
                                                Open settings
                                            </Link>
                                        ) : undefined
                                    }
                                >
                                    {checklist.settings.done
                                        ? 'This branch has its own details or prices set.'
                                        : "Nothing set yet, so it uses headquarters' MoMo number, fees and prices."}
                                </ChecklistStep>
                            ) : (
                                <ChecklistStep done={null} title="Branch details, fees and prices">
                                    Headquarters uses the company settings.
                                </ChecklistStep>
                            )}
                        </ol>
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}
