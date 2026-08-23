import { Head, Link, router, usePage } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { SelectInput } from '@/components/ui/SelectInput';
import { EmptyState } from '@/components/ui/EmptyState';
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

    function applyFilter(next: Partial<AgentFilters>) {
        router.get('/agents', { ...filters, ...next }, { preserveState: true, replace: true });
    }

    function destroy(agent: Agent) {
        if (confirm(`Remove agent "${agent.name}"?`)) {
            router.delete(`/agents/${agent.uuid}`);
        }
    }

    return (
        <AppLayout title="Agents">
            <Head title="Agents" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold text-slate-900">Agents</h1>
                {canManage && (
                    <Link href="/agents/create">
                        <Button>Add Agent</Button>
                    </Link>
                )}
            </div>

            <Card className="mb-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <SelectInput
                        id="zone"
                        label="Zone"
                        value={filters.zone_uuid ?? ''}
                        onChange={(e) => applyFilter({ zone_uuid: e.target.value || undefined })}
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
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </SelectInput>
                </div>
            </Card>

            {agents.data.length === 0 ? (
                <EmptyState title="No agents found" description="Add a field agent to get started." />
            ) : (
                <>
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
                            {agents.data.map((agent) => (
                                <tr key={agent.uuid}>
                                    <Td>{agent.name}</Td>
                                    <Td>{agent.phone}</Td>
                                    <Td>{agent.zone_name ?? '—'}</Td>
                                    <Td>{formatCurrency(agent.salary)}</Td>
                                    <Td>
                                        <Badge tone={agent.status === 'active' ? 'green' : 'slate'}>{agent.status}</Badge>
                                    </Td>
                                    <Td>{lastSyncLabel(agent.last_sync_at)}</Td>
                                    {canManage && (
                                        <Td>
                                            <div className="flex gap-3">
                                                <Link href={`/agents/${agent.uuid}/edit`} className="text-blue-600 hover:underline">
                                                    Edit
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() => destroy(agent)}
                                                    className="text-red-600 hover:underline"
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination links={agents.links} />
                </>
            )}
        </AppLayout>
    );
}
