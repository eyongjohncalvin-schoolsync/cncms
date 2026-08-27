import { Head, Link } from '@inertiajs/react';
import { IconPlus } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { Dropdown, DropdownItem } from '@/components/ui/Dropdown';
import type { Branch, PaginatedResponse } from '@/types';

interface BranchesIndexProps {
    branches: PaginatedResponse<Branch>;
}

export default function BranchesIndex({ branches }: BranchesIndexProps) {
    return (
        <AppLayout title="Branches">
            <Head title="Branches" />

            <div className="animate-fade-up mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl text-slate-900">Branches</h2>
                    <p className="mt-1 text-sm text-slate-500">Manage the offices/locations zones are grouped into.</p>
                </div>
                <Link
                    href="/branches/create"
                    className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition-colors hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                >
                    <IconPlus size={18} stroke={2} />
                    Add Branch
                </Link>
            </div>

            {branches.data.length === 0 ? (
                <div className="animate-fade-up" style={{ animationDelay: '0.05s' }}>
                    <EmptyState title="No branches found" description="Add a branch to get started." />
                </div>
            ) : (
                <div className="animate-fade-up" style={{ animationDelay: '0.05s' }}>
                    <Table>
                        <TableHead>
                            <Th>Name</Th>
                            <Th>Zones</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {branches.data.map((branch) => (
                                <tr key={branch.uuid} className="transition-colors hover:bg-slate-50/70">
                                    <Td className="font-medium text-slate-900">{branch.name}</Td>
                                    <Td>{branch.zone_count ?? 0}</Td>
                                    <Td>
                                        <Dropdown label={`Actions for ${branch.name}`}>
                                            <DropdownItem href={`/branches/${branch.uuid}/edit`}>Edit</DropdownItem>
                                            <DropdownItem
                                                href={`/branches/${branch.uuid}`}
                                                method="delete"
                                                onBefore={() => confirm('Delete this branch?')}
                                                variant="danger"
                                            >
                                                Delete
                                            </DropdownItem>
                                        </Dropdown>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <Pagination links={branches.links} />
        </AppLayout>
    );
}
