import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { IconKey, IconMapPinCog, IconUserPlus, IconUsersGroup } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { Badge } from '@/components/ui/Badge';
import { SelectInput } from '@/components/ui/SelectInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Dropdown, DropdownItem } from '@/components/ui/Dropdown';
import { ChangeZoneModal } from '@/components/agents/ChangeZoneModal';
import { hasPermission } from '@/lib/permissions';
import type { Agent, PageProps, PaginatedResponse, Zone } from '@/types';

interface AgentFilters {
    zone_uuid?: string;
    status?: string;
}

interface AgentsIndexProps {
    filters: AgentFilters;
    agents: PaginatedResponse<Agent>;
    zones: Zone[];
    /** May create staff accounts (users.manage) — the "Create login" action. */
    can_create_login: boolean;
}

function lastSyncLabel(lastSyncAt: string | null): string {
    return lastSyncAt ?? 'Never';
}

/**
 * Field agents (owner, 2026-10-28): a field agent is a user — added, renamed,
 * deactivated and paid on the Users page. This page shows their zones and
 * workload, moves an agent to another zone, and gives an old agent record
 * without a login one.
 */
export default function AgentsIndex({ filters, agents, zones, can_create_login: canCreateLogin }: AgentsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    // AgentPolicy::update → `agents.manage`: changing an agent's zones.
    const canManage = hasPermission(auth.user?.permissions, 'agents.manage');

    const [isFiltering, setIsFiltering] = useState(false);
    const [zoneChangeAgent, setZoneChangeAgent] = useState<Agent | null>(null);
    const [loginAgent, setLoginAgent] = useState<Agent | null>(null);

    function applyFilter(next: Partial<AgentFilters>) {
        router.get(
            '/agents',
            { ...filters, ...next },
            {
                preserveState: true,
                replace: true,
                onStart: () => setIsFiltering(true),
                onFinish: () => setIsFiltering(false),
            },
        );
    }

    return (
        <AppLayout title="Field agents">
            <Head title="Field agents" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4 animate-fade-up">
                <div>
                    <h1 className="font-display text-2xl font-semibold tracking-tight text-slate-900">Field agents</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        The zones each field agent covers and their workload. A field agent is a user: add them, change
                        their role or deactivate them on the Users page.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href="/zones/coverage"
                        className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50"
                    >
                        <IconUsersGroup size={18} stroke={2} />
                        Give zones
                    </Link>
                    {canCreateLogin && (
                        <Link
                            href="/users"
                            className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition-colors hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2"
                        >
                            <IconUserPlus size={18} stroke={2} />
                            Add a field agent (Users)
                        </Link>
                    )}
                </div>
            </div>

            <div
                className="mb-4 animate-fade-up rounded-lg border border-slate-200 bg-slate-50 p-4"
                style={{ animationDelay: '0.08s' }}
            >
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <SelectInput
                        id="zone"
                        label="Zone"
                        value={filters.zone_uuid ?? ''}
                        onChange={(e) => applyFilter({ zone_uuid: e.target.value || undefined })}
                        className="w-full rounded-lg bg-white sm:w-auto"
                    >
                        <option value="">All zones</option>
                        {zones.map((zone) => (
                            <option key={zone.uuid} value={zone.uuid}>
                                {zone.name}
                            </option>
                        ))}
                    </SelectInput>
                    <SelectInput
                        id="status"
                        label="Status"
                        value={filters.status ?? ''}
                        onChange={(e) => applyFilter({ status: e.target.value || undefined })}
                        className="w-full rounded-lg bg-white sm:w-auto"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </SelectInput>
                    {isFiltering && <LoadingSpinner className="mb-2 text-slate-400" />}
                </div>
            </div>

            {agents.data.length === 0 ? (
                <div className="animate-fade-up" style={{ animationDelay: '0.16s' }}>
                    <EmptyState title="No field agents found" description="Add a user with the Field agent role on the Users page." />
                </div>
            ) : (
                <Card className="animate-fade-up p-0" style={{ animationDelay: '0.16s' }}>
                    <Table>
                        <TableHead>
                            <Th>Name</Th>
                            <Th>Phone</Th>
                            <Th>Zones</Th>
                            <Th>Workload</Th>
                            <Th>Status</Th>
                            <Th>Last Sync</Th>
                            {(canManage || canCreateLogin) && <Th>Actions</Th>}
                        </TableHead>
                        <TableBody>
                            {agents.data.map((agent) => (
                                <tr key={agent.uuid} className="transition-colors hover:bg-slate-50">
                                    <Td className="font-medium text-slate-900">
                                        {agent.name}
                                        {agent.has_login === false && (
                                            <span className="ml-1.5" title="An old agent record from before agents were users — it can't sign in.">
                                                <Badge tone="yellow">No login</Badge>
                                            </span>
                                        )}
                                    </Td>
                                    <Td className="whitespace-nowrap">{agent.phone}</Td>
                                    <Td>
                                        {(agent.zones_covered ?? 0) === 0 ? (
                                            <Badge tone="red">No zones</Badge>
                                        ) : (agent.zones_covered ?? 0) === 1 ? (
                                            (agent.zone_name ?? '1 zone')
                                        ) : (
                                            `${agent.zones_covered} zones`
                                        )}
                                    </Td>
                                    <Td className="whitespace-nowrap">
                                        {(agent.customers_covered ?? 0).toLocaleString('en-US')} customers
                                        {agent.customer_limit ? ` / ${agent.customer_limit}` : ''}{' '}
                                        {agent.workload_status && (
                                            <Badge tone={agent.workload_status === 'overloaded' ? 'red' : agent.workload_status === 'busy' ? 'yellow' : 'green'}>
                                                {agent.workload_status === 'overloaded' ? 'Overloaded' : agent.workload_status === 'busy' ? 'Busy' : 'Normal'}
                                            </Badge>
                                        )}
                                    </Td>
                                    <Td>
                                        <Badge tone={agent.status === 'active' ? 'green' : 'slate'}>{agent.status}</Badge>
                                    </Td>
                                    <Td className="whitespace-nowrap">{lastSyncLabel(agent.last_sync_at)}</Td>
                                    {(canManage || canCreateLogin) && (
                                        <Td className="whitespace-nowrap">
                                            <Dropdown label={`Actions for ${agent.name}`}>
                                                {canManage && agent.status === 'active' && (
                                                    <DropdownItem onClick={() => setZoneChangeAgent(agent)} icon={<IconMapPinCog size={16} stroke={1.75} />}>
                                                        Change Zone
                                                    </DropdownItem>
                                                )}
                                                {canCreateLogin && agent.has_login === false && (
                                                    <DropdownItem onClick={() => setLoginAgent(agent)} icon={<IconKey size={16} stroke={1.75} />}>
                                                        Create login
                                                    </DropdownItem>
                                                )}
                                                {canCreateLogin && agent.has_login !== false && <DropdownItem href="/users">Edit on the Users page</DropdownItem>}
                                            </Dropdown>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={agents.links} />
                    </div>
                </Card>
            )}

            <ChangeZoneModal agent={zoneChangeAgent} zones={zones} onClose={() => setZoneChangeAgent(null)} />
            {loginAgent && <CreateLoginModal agent={loginAgent} onClose={() => setLoginAgent(null)} />}
        </AppLayout>
    );
}

/** Gives an old agent record without a login a field-agent user account, keeping its zones. */
function CreateLoginModal({ agent, onClose }: { agent: Agent; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ username: '', email: agent.email ?? '', password: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/agents/${agent.uuid}/login`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal open onClose={onClose} title={`Create a login — ${agent.name}`}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-slate-600">
                    {agent.name} becomes a user with the Field agent role and keeps their zones. Manage them on the Users page from now on.
                </p>
                <TextInput id="login-username" label="Username" value={data.username} onChange={(e) => setData('username', e.target.value)} error={errors.username} required />
                <TextInput id="login-email" type="email" label="Email" value={data.email} onChange={(e) => setData('email', e.target.value)} error={errors.email} required />
                <TextInput
                    id="login-password"
                    type="password"
                    minLength={8}
                    label="Password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    required
                />
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                        Create login
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
