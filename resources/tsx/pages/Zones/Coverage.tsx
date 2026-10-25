import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { IconAlertTriangle, IconUsers, IconX } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { InfoTip } from '@/components/ui/InfoTip';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import {
    applyChange,
    covers,
    workloads,
    workloadStatus,
    zoneCounts,
    type CoverageAgent,
    type CoverageChange,
    type CoverageZone,
    type WorkloadStatus,
} from '@/lib/zoneCoverage';

interface ZoneCoverageProps {
    branch: { uuid: string; name: string; is_headquarters: boolean };
    branches: { uuid: string; name: string }[];
    zones: CoverageZone[];
    agents: CoverageAgent[];
    limit: number | null;
    branch_limit: number | null;
    headquarters_limit: number | null;
    can_manage: boolean;
    can_edit_limit: boolean;
}

type Filter = { kind: 'all' } | { kind: 'uncovered' } | { kind: 'agent'; uuid: string };

const STATUS_BADGE: Record<WorkloadStatus, { tone: 'green' | 'yellow' | 'red' | 'slate'; label: string } | null> = {
    none: null,
    normal: { tone: 'green', label: 'Normal' },
    busy: { tone: 'yellow', label: 'Busy' },
    overloaded: { tone: 'red', label: 'Overloaded' },
};

const BAR_COLOR: Record<WorkloadStatus, string> = {
    none: 'bg-slate-400',
    normal: 'bg-green-600',
    busy: 'bg-yellow-500',
    overloaded: 'bg-red-600',
};

function formatCustomers(value: number): string {
    return Math.round(value).toLocaleString('en-US');
}

/**
 * Zone coverage (App\Http\Controllers\ZoneCoverageController): which field
 * agents cover which zones of one branch. Each zone has one responsible
 * collector — printed on its bills — and may have helpers. Any change is
 * previewed first (workload before → after, zones left without a
 * collector), worked out here with the same rules as the server
 * (@/lib/zoneCoverage). Tick boxes and dropdowns only — no dragging — so it
 * works the same with a mouse, a keyboard or a finger.
 */
export default function ZoneCoverage({ branch, branches, zones, agents, limit, branch_limit, headquarters_limit, can_manage, can_edit_limit }: ZoneCoverageProps) {
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [filter, setFilter] = useState<Filter>({ kind: 'all' });
    const [search, setSearch] = useState('');
    const [pending, setPending] = useState<CoverageChange | null>(null);
    const [saving, setSaving] = useState(false);
    const [limitDraft, setLimitDraft] = useState(String((branch.is_headquarters ? headquarters_limit : branch_limit) ?? ''));

    const agentsByUuid = useMemo(() => new Map(agents.map((agent) => [agent.uuid, agent])), [agents]);
    const activeAgents = useMemo(() => agents.filter((agent) => agent.active), [agents]);
    const load = useMemo(() => workloads(zones), [zones]);
    const counts = useMemo(() => zoneCounts(zones), [zones]);

    // A zone needs attention when nobody is responsible, or the responsible agent is inactive.
    const isUncovered = (zone: CoverageZone) => zone.responsible === null || agentsByUuid.get(zone.responsible)?.active === false;
    const uncovered = zones.filter(isUncovered);

    const visibleZones = zones.filter((zone) => {
        if (search.trim() !== '' && !zone.name.toLowerCase().includes(search.trim().toLowerCase())) {
            return false;
        }

        if (filter.kind === 'uncovered') {
            return isUncovered(zone);
        }

        return filter.kind === 'agent' ? covers(zone, filter.uuid) : true;
    });

    const selectedZones = zones.filter((zone) => selected.has(zone.uuid));
    const allVisibleSelected = visibleZones.length > 0 && visibleZones.every((zone) => selected.has(zone.uuid));

    function toggle(uuid: string) {
        setSelected((current) => {
            const next = new Set(current);
            next.has(uuid) ? next.delete(uuid) : next.add(uuid);
            return next;
        });
    }

    function toggleAllVisible() {
        setSelected((current) => {
            const next = new Set(current);
            visibleZones.forEach((zone) => (allVisibleSelected ? next.delete(zone.uuid) : next.add(zone.uuid)));
            return next;
        });
    }

    function propose(action: CoverageChange['action'], agentUuid: string | null, zoneUuids: string[]) {
        if (zoneUuids.length === 0 || (action !== 'clear' && !agentUuid)) {
            return;
        }

        setPending({ action, agentUuid, zoneUuids, keepPreviousAsHelper: false });
    }

    function confirm() {
        if (!pending) {
            return;
        }

        router.patch(
            '/zones/coverage',
            {
                branch_uuid: branch.uuid,
                action: pending.action,
                agent_uuid: pending.agentUuid,
                zone_uuids: pending.zoneUuids,
                keep_previous_as_helper: pending.keepPreviousAsHelper,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setPending(null);
                    setSelected(new Set());
                },
            },
        );
    }

    function saveLimit() {
        router.put('/zones/coverage/limit', { branch_uuid: branch.uuid, limit: limitDraft.trim() === '' ? null : Number(limitDraft) }, { preserveScroll: true });
    }

    return (
        <AppLayout title="Zone coverage">
            <Head title="Zone coverage" />

            <div className="animate-fade-up mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="flex items-center gap-2 font-display text-2xl text-slate-900">
                        Zone coverage — {branch.name}
                        <InfoTip
                            label="About zone coverage"
                            text="Each zone has one responsible collector, whose name is printed on its bills. Helpers can collect there too. One agent can cover one zone, several, or all of them."
                        />
                    </h2>
                    <p className="mt-1 text-sm text-slate-500">Who collects in which zone, and how busy each agent is.</p>
                </div>
                {branches.length > 1 && (
                    <SelectInput
                        id="coverage-branch"
                        label="Branch"
                        value={branch.uuid}
                        onChange={(event) => router.get('/zones/coverage', { branch: event.target.value })}
                        className="min-w-48"
                    >
                        {branches.map((option) => (
                            <option key={option.uuid} value={option.uuid}>
                                {option.name}
                            </option>
                        ))}
                    </SelectInput>
                )}
            </div>

            {uncovered.length > 0 && (
                <div role="alert" className="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                    <IconAlertTriangle size={18} className="shrink-0 text-red-600" />
                    <span className="font-semibold">
                        {uncovered.length === 1 ? '1 zone has' : `${uncovered.length} zones have`} no active responsible collector
                    </span>
                    <span className="text-red-800">— their bills print no collector and nobody is accountable for their money.</span>
                    <button type="button" onClick={() => setFilter({ kind: 'uncovered' })} className="ml-auto font-semibold underline underline-offset-2 hover:text-red-700">
                        Show them
                    </button>
                </div>
            )}

            {can_manage && zones.length > 0 && activeAgents.length > 0 && zones.every((zone) => zone.responsible === null) && (
                <Card className="mb-4">
                    <CardBody className="flex flex-wrap items-end gap-3">
                        <div className="text-sm text-slate-700">
                            <p className="font-semibold text-slate-900">Starting out? One agent can look after every zone.</p>
                            <p>You can split the zones among more agents later.</p>
                        </div>
                        <SelectInput
                            id="give-all"
                            label={`Give all ${zones.length} zones to`}
                            value=""
                            onChange={(event) => propose('responsible', event.target.value, zones.map((zone) => zone.uuid))}
                            className="min-w-56"
                        >
                            <option value="">Choose an agent…</option>
                            {activeAgents.map((agent) => (
                                <option key={agent.uuid} value={agent.uuid}>
                                    {agent.name}
                                </option>
                            ))}
                        </SelectInput>
                    </CardBody>
                </Card>
            )}

            <Card className="mb-4">
                <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <IconUsers size={16} /> Agents
                    </h3>
                    {can_edit_limit ? (
                        <div className="flex flex-wrap items-end gap-2">
                            <TextInput
                                id="agent-limit"
                                type="number"
                                min="1"
                                label="Customers per agent"
                                tip="When an agent looks after more customers than this, they show as Busy (from 80%) or Overloaded. It is a guide for management — nothing is ever blocked."
                                value={limitDraft}
                                onChange={(event) => setLimitDraft(event.target.value)}
                                placeholder={branch.is_headquarters ? 'No limit' : headquarters_limit !== null ? `HQ: ${headquarters_limit}` : 'No limit'}
                                className="w-36"
                            />
                            <Button variant="secondary" onClick={saveLimit}>
                                Save limit
                            </Button>
                        </div>
                    ) : (
                        <p className="text-sm text-slate-500">{limit !== null ? `Limit: ${limit} customers per agent` : 'No customer limit set'}</p>
                    )}
                </CardHeader>
                <CardBody>
                    {agents.length === 0 ? (
                        <p className="text-sm text-slate-500">This branch has no field agents yet. Add one on the Agents page.</p>
                    ) : (
                        // A scrollable strip on a phone, a grid on wider screens. Tapping a card shows that agent's zones.
                        <div className="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-1 sm:grid sm:grid-cols-2 sm:overflow-visible lg:grid-cols-3 xl:grid-cols-4">
                            {agents.map((agent) => {
                                const customers = load.get(agent.uuid) ?? 0;
                                const status = workloadStatus(customers, limit);
                                const badge = STATUS_BADGE[status];
                                const isFiltered = filter.kind === 'agent' && filter.uuid === agent.uuid;

                                return (
                                    <button
                                        key={agent.uuid}
                                        type="button"
                                        onClick={() => setFilter(isFiltered ? { kind: 'all' } : { kind: 'agent', uuid: agent.uuid })}
                                        aria-pressed={isFiltered}
                                        className={`min-w-56 snap-start rounded-xl border p-3 text-left transition-colors ${isFiltered ? 'border-blue-500 bg-blue-50' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="truncate text-sm font-semibold text-slate-900">{agent.name}</span>
                                            {!agent.active ? <Badge tone="slate">Inactive</Badge> : badge && <Badge tone={badge.tone}>{badge.label}</Badge>}
                                        </div>
                                        <p className="mt-1 text-xs text-slate-600">
                                            {counts.get(agent.uuid) ?? 0} zones · {formatCustomers(customers)} customers
                                            {limit !== null && ` / ${limit}`}
                                        </p>
                                        {limit !== null && (
                                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                                <div className={`h-full rounded-full ${BAR_COLOR[status]}`} style={{ width: `${Math.min(100, (customers / limit) * 100)}%` }} />
                                            </div>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </CardBody>
            </Card>

            <Card>
                <CardHeader className="flex flex-wrap items-end gap-3">
                    <TextInput id="zone-search" label="Find a zone" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Zone name" className="w-56" />
                    <SelectInput
                        id="zone-filter"
                        label="Show"
                        value={filter.kind === 'agent' ? filter.uuid : filter.kind}
                        onChange={(event) => {
                            const value = event.target.value;
                            setFilter(value === 'all' || value === 'uncovered' ? { kind: value } : { kind: 'agent', uuid: value });
                        }}
                    >
                        <option value="all">All zones ({zones.length})</option>
                        <option value="uncovered">No collector ({uncovered.length})</option>
                        {agents.map((agent) => (
                            <option key={agent.uuid} value={agent.uuid}>
                                {agent.name}'s zones
                            </option>
                        ))}
                    </SelectInput>
                </CardHeader>

                {zones.length === 0 ? (
                    <CardBody>
                        <EmptyState title="No zones yet" description="Add this branch's zones first, then give them to agents." />
                    </CardBody>
                ) : (
                    <ul className="divide-y divide-slate-100">
                        {can_manage && (
                            <li className="flex items-center gap-3 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <input
                                    type="checkbox"
                                    checked={allVisibleSelected}
                                    onChange={toggleAllVisible}
                                    aria-label={`Select all ${visibleZones.length} zones shown`}
                                    className="h-5 w-5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                />
                                <span>Select all {visibleZones.length} shown</span>
                            </li>
                        )}
                        {visibleZones.map((zone) => {
                            const responsible = zone.responsible !== null ? agentsByUuid.get(zone.responsible) : undefined;
                            const helperOptions = activeAgents.filter((agent) => !covers(zone, agent.uuid));

                            return (
                                <li key={zone.uuid} className="flex flex-col gap-3 px-4 py-3 md:flex-row md:items-center">
                                    <div className="flex min-w-0 items-center gap-3 md:w-72">
                                        {can_manage && (
                                            <input
                                                type="checkbox"
                                                checked={selected.has(zone.uuid)}
                                                onChange={() => toggle(zone.uuid)}
                                                aria-label={`Select ${zone.name}`}
                                                className="h-5 w-5 shrink-0 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                            />
                                        )}
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold text-slate-900">{zone.name}</p>
                                            <p className="text-xs text-slate-500">{zone.customers.toLocaleString('en-US')} active customers</p>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2 md:w-64">
                                        {can_manage ? (
                                            <select
                                                aria-label={`Responsible collector for ${zone.name}`}
                                                value={zone.responsible ?? ''}
                                                onChange={(event) => (event.target.value === '' ? propose('clear', null, [zone.uuid]) : propose('responsible', event.target.value, [zone.uuid]))}
                                                className="min-h-[40px] w-full rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                                            >
                                                <option value="">— No collector —</option>
                                                {agents
                                                    .filter((agent) => agent.active || agent.uuid === zone.responsible)
                                                    .map((agent) => (
                                                        <option key={agent.uuid} value={agent.uuid} disabled={!agent.active}>
                                                            {agent.name}
                                                            {!agent.active ? ' (inactive)' : ''}
                                                        </option>
                                                    ))}
                                            </select>
                                        ) : (
                                            <span className="text-sm text-slate-800">{responsible?.name ?? '—'}</span>
                                        )}
                                        {isUncovered(zone) && <Badge tone="red">{zone.responsible === null ? 'No collector' : 'Collector inactive'}</Badge>}
                                    </div>

                                    <div className="flex flex-1 flex-wrap items-center gap-2">
                                        {zone.helpers.map((helperUuid) => (
                                            <span key={helperUuid} className="inline-flex items-center gap-1 rounded-full bg-slate-100 py-1 pl-3 pr-1 text-xs font-medium text-slate-700">
                                                {agentsByUuid.get(helperUuid)?.name ?? 'Unknown'}
                                                {can_manage && (
                                                    <button
                                                        type="button"
                                                        onClick={() => propose('remove', helperUuid, [zone.uuid])}
                                                        aria-label={`Remove ${agentsByUuid.get(helperUuid)?.name ?? 'helper'} from ${zone.name}`}
                                                        className="inline-flex h-6 w-6 items-center justify-center rounded-full hover:bg-slate-200"
                                                    >
                                                        <IconX size={14} />
                                                    </button>
                                                )}
                                            </span>
                                        ))}
                                        {can_manage && helperOptions.length > 0 && (
                                            <select
                                                aria-label={`Add a helper to ${zone.name}`}
                                                value=""
                                                onChange={(event) => propose('helper', event.target.value, [zone.uuid])}
                                                className="min-h-[36px] rounded-full border-dashed border-slate-300 py-1 text-xs text-slate-600 focus:border-blue-500 focus:ring-blue-500"
                                            >
                                                <option value="">+ Helper</option>
                                                {helperOptions.map((agent) => (
                                                    <option key={agent.uuid} value={agent.uuid}>
                                                        {agent.name}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                        {visibleZones.length === 0 && <li className="px-4 py-6 text-center text-sm text-slate-500">No zones match.</li>}
                    </ul>
                )}
            </Card>

            {can_manage && selected.size > 0 && (
                <BulkBar
                    count={selected.size}
                    agents={activeAgents}
                    coveringAgents={agents.filter((agent) => selectedZones.some((zone) => covers(zone, agent.uuid)))}
                    onAssign={(agentUuid) => propose('responsible', agentUuid, [...selected])}
                    onHelper={(agentUuid) => propose('helper', agentUuid, [...selected])}
                    onRemove={(agentUuid) => propose('remove', agentUuid, [...selected])}
                    onClear={() => setSelected(new Set())}
                />
            )}

            <PreviewModal
                change={pending}
                zones={zones}
                agentsByUuid={agentsByUuid}
                limit={limit}
                saving={saving}
                onKeepPrevious={(keep) => setPending((current) => (current ? { ...current, keepPreviousAsHelper: keep } : current))}
                onCancel={() => setPending(null)}
                onConfirm={confirm}
            />
        </AppLayout>
    );
}

function BulkBar({
    count,
    agents,
    coveringAgents,
    onAssign,
    onHelper,
    onRemove,
    onClear,
}: {
    count: number;
    agents: CoverageAgent[];
    coveringAgents: CoverageAgent[];
    onAssign: (agentUuid: string) => void;
    onHelper: (agentUuid: string) => void;
    onRemove: (agentUuid: string) => void;
    onClear: () => void;
}) {
    const selectClass = 'min-h-[40px] rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500';

    return (
        <div className="sticky bottom-4 z-20 mt-4 flex flex-wrap items-center gap-2 rounded-xl border border-blue-200 bg-white p-3 shadow-lg shadow-slate-900/10">
            <span aria-live="polite" className="mr-2 text-sm font-semibold text-slate-900">
                {count === 1 ? '1 zone selected' : `${count} zones selected`}
            </span>
            <select aria-label="Make an agent responsible for the selected zones" value="" onChange={(event) => onAssign(event.target.value)} className={selectClass}>
                <option value="">Make responsible…</option>
                {agents.map((agent) => (
                    <option key={agent.uuid} value={agent.uuid}>
                        {agent.name}
                    </option>
                ))}
            </select>
            <select aria-label="Add a helper to the selected zones" value="" onChange={(event) => onHelper(event.target.value)} className={selectClass}>
                <option value="">Add helper…</option>
                {agents.map((agent) => (
                    <option key={agent.uuid} value={agent.uuid}>
                        {agent.name}
                    </option>
                ))}
            </select>
            {coveringAgents.length > 0 && (
                <select aria-label="Take an agent off the selected zones" value="" onChange={(event) => onRemove(event.target.value)} className={selectClass}>
                    <option value="">Take off…</option>
                    {coveringAgents.map((agent) => (
                        <option key={agent.uuid} value={agent.uuid}>
                            {agent.name}
                        </option>
                    ))}
                </select>
            )}
            <Button variant="ghost" onClick={onClear} className="ml-auto">
                Clear selection
            </Button>
        </div>
    );
}

function PreviewModal({
    change,
    zones,
    agentsByUuid,
    limit,
    saving,
    onKeepPrevious,
    onCancel,
    onConfirm,
}: {
    change: CoverageChange | null;
    zones: CoverageZone[];
    agentsByUuid: Map<string, CoverageAgent>;
    limit: number | null;
    saving: boolean;
    onKeepPrevious: (keep: boolean) => void;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    const after = change ? applyChange(zones, change) : zones;
    const before = workloads(zones);
    const afterLoad = workloads(after);
    const beforeCounts = zoneCounts(zones);
    const afterCounts = zoneCounts(after);

    const affected = [...new Set([...before.keys(), ...afterLoad.keys()])]
        .filter((uuid) => Math.round(before.get(uuid) ?? 0) !== Math.round(afterLoad.get(uuid) ?? 0) || (beforeCounts.get(uuid) ?? 0) !== (afterCounts.get(uuid) ?? 0))
        .map((uuid) => ({ uuid, name: agentsByUuid.get(uuid)?.name ?? 'Unknown' }));

    // applyChange() hands back the very same object for a zone it leaves alone.
    const original = new Map(zones.map((zone) => [zone.uuid, zone]));
    const changedZones = after.filter((zone) => zone !== original.get(zone.uuid));
    const newCollector = changedZones.filter((zone) => zone.responsible !== null && zone.responsible !== original.get(zone.uuid)?.responsible);
    const leftUncovered = changedZones.filter((zone) => zone.responsible === null && original.get(zone.uuid)?.responsible !== null);
    const replacesSomeone = change?.action === 'responsible' && zones.some((zone) => change.zoneUuids.includes(zone.uuid) && zone.responsible !== null && zone.responsible !== change.agentUuid);
    const agentName = change?.agentUuid ? (agentsByUuid.get(change.agentUuid)?.name ?? 'the agent') : '';

    return (
        <Modal open={change !== null} onClose={onCancel} title="Check the change">
            {change && changedZones.length === 0 ? (
                <p className="text-sm text-slate-600">Nothing would change — those zones are already set up that way.</p>
            ) : (
                <div className="flex flex-col gap-4 text-sm">
                    {affected.length > 0 && (
                        <div>
                            <p className="mb-2 font-semibold text-slate-900">Workload</p>
                            <ul className="flex flex-col gap-1.5">
                                {affected.map(({ uuid, name }) => {
                                    const was = before.get(uuid) ?? 0;
                                    const will = afterLoad.get(uuid) ?? 0;
                                    const status = STATUS_BADGE[workloadStatus(will, limit)];

                                    return (
                                        <li key={uuid} className="flex flex-wrap items-center gap-2 text-slate-700">
                                            <span className="font-medium text-slate-900">{name}:</span>
                                            <span>
                                                {beforeCounts.get(uuid) ?? 0} zones / {formatCustomers(was)} → {afterCounts.get(uuid) ?? 0} zones / {formatCustomers(will)} customers
                                            </span>
                                            {status && <Badge tone={status.tone}>{status.label}</Badge>}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}

                    {newCollector.length > 0 && (
                        <p className="rounded-lg bg-blue-50 px-3 py-2 text-blue-900">
                            {newCollector.length === 1 ? '1 zone’s bills' : `${newCollector.length} zones’ bills`} will print <strong>{agentName}</strong> as the collector.
                        </p>
                    )}

                    {leftUncovered.length > 0 && (
                        <p className="rounded-lg bg-red-50 px-3 py-2 text-red-900">
                            <strong>{leftUncovered.map((zone) => zone.name).join(', ')}</strong> will have no responsible collector.
                        </p>
                    )}

                    {replacesSomeone && (
                        <label className="flex items-start gap-2 text-slate-700">
                            <input
                                type="checkbox"
                                checked={change?.keepPreviousAsHelper ?? false}
                                onChange={(event) => onKeepPrevious(event.target.checked)}
                                className="mt-0.5 h-5 w-5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                            />
                            <span>Keep the previous collector in those zones as a helper (otherwise they stop covering them).</span>
                        </label>
                    )}
                </div>
            )}

            <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onCancel}>
                    Cancel
                </Button>
                <Button onClick={onConfirm} disabled={saving || changedZones.length === 0}>
                    {saving ? 'Saving…' : 'Confirm'}
                </Button>
            </div>
        </Modal>
    );
}
