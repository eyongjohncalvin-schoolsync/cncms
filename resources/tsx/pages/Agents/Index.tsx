import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { IconMapPinCog, IconUserPlus } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { Badge } from '@/components/ui/Badge';
import { SelectInput } from '@/components/ui/SelectInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Dropdown, DropdownItem, DropdownDivider } from '@/components/ui/Dropdown';
import { ChangeZoneModal } from '@/components/agents/ChangeZoneModal';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Agent, PageProps, PaginatedResponse, Zone } from '@/types';

interface AgentFilters {
    zone_uuid?: string;
    status?: string;
}

interface AgentsIndexProps {
    filters: AgentFilters;
    agents: PaginatedResponse<Agent>;
    zones: Zone[];
}

const MANAGE_ROLES = ['super', 'admin', 'manager'];

function lastSyncLabel(lastSyncAt: string | null): string {
    return lastSyncAt ?? 'Never';
}

export default function AgentsIndex({ filters, agents, zones }: AgentsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role ?? null;
    const canManage = role !== null && MANAGE_ROLES.includes(role);

    const [isFiltering, setIsFiltering] = useState(false);
    const [zoneChangeAgent, setZoneChangeAgent] = useState<Agent | null>(null);

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

    function destroy(agent: Agent) {
        if (confirm(`Remove agent "${agent.name}"?`)) {
            router.delete(`/agents/${agent.uuid}`);
        }
    }

    // Recomputed only when the agents list itself changes, not on every
    // unrelated re-render (e.g. toggling isFiltering).
    const rows = useMemo(
        () =>
            agents.data.map((agent) => ({
                agent,
                formattedSalary: formatCurrency(agent.salary),
            })),
        [agents.data],
    );

    return (
        <AppLayout title="Agents">
            <Head title="Agents" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4 animate-fade-up">
                <div>
                    <h1 className="font-display text-2xl font-semibold tracking-tight text-slate-900">Agents</h1>
                    <p className="mt-1 text-sm text-slate-500">Field agents assigned to zones, their salaries, and sync status.</p>
                </div>
                {canManage && (
                    <Link
                        href="/agents/create"
                        className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition-colors hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2"
                    >
                        <IconUserPlus size={18} stroke={2} />
                        Add Agent
                    </Link>
                )}
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
                    <EmptyState title="No agents found" description="Add a field agent to get started." />
                </div>
            ) : (
                <Card className="animate-fade-up p-0" style={{ animationDelay: '0.16s' }}>
                    <Table>
                        <TableHead>
                            <Th>Name</Th>
                            <Th>Phone</Th>
                            <Th>Zone</Th>
                            <Th>Salary</Th>
                            <Th>Status</Th>
                            <Th>Last Sync</Th>
                            {canManage && <Th>Actions</Th>}
                        </TableHead>
                        <TableBody>
                            {rows.map(({ agent, formattedSalary }) => (
                                <tr key={agent.uuid} className="transition-colors hover:bg-slate-50">
                                    <Td className="font-medium text-slate-900">{agent.name}</Td>
                                    <Td className="whitespace-nowrap">{agent.phone}</Td>
                                    <Td>{agent.zone_name ?? '—'}</Td>
                                    <Td className="whitespace-nowrap">{formattedSalary}</Td>
                                    <Td>
                                        <Badge tone={agent.status === 'active' ? 'green' : 'slate'}>{agent.status}</Badge>
                                    </Td>
                                    <Td className="whitespace-nowrap">{lastSyncLabel(agent.last_sync_at)}</Td>
                                    {canManage && (
                                        <Td className="whitespace-nowrap">
                                            <Dropdown label={`Actions for ${agent.name}`}>
                                                <DropdownItem onClick={() => setZoneChangeAgent(agent)} icon={<IconMapPinCog size={16} stroke={1.75} />}>
                                                    Change Zone
                                                </DropdownItem>
                                                <DropdownItem href={`/agents/${agent.uuid}/edit`}>Edit</DropdownItem>
                                                <DropdownDivider />
                                                <DropdownItem onClick={() => destroy(agent)} variant="danger">
                                                    Delete
                                                </DropdownItem>
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
        </AppLayout>
    );
}
