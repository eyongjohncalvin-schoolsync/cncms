import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { IconUsers } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/ui/Pagination';
import type { PaginationLink } from '@/types';

interface WorkspaceUser {
    uuid: string;
    name: string;
    email: string;
    username: string | null;
    workspace: string;
    role: string;
    status: string;
    can_impersonate: boolean;
}

/**
 * Every workspace user, searchable, with "Log in as" for support
 * (App\Http\Controllers\Landlord\UserController, App\Support\Impersonation).
 */
export default function LandlordUsersIndex({
    users,
    filters,
}: {
    users: { data: WorkspaceUser[]; links: PaginationLink[]; total: number };
    filters: { search: string };
}) {
    const [search, setSearch] = useState(filters.search);

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get('/landlord/users', search ? { search } : {}, { preserveState: true });
    }

    function impersonate(user: WorkspaceUser) {
        if (confirm(`Log in as ${user.name} (${user.workspace})? You will see and act exactly as them, and everything you change is recorded under your name.`)) {
            router.post(`/landlord/impersonate/${user.uuid}`);
        }
    }

    return (
        <LandlordLayout title="Users" breadcrumbs={[{ label: 'Landlord', href: '/landlord' }, { label: 'Users' }]}>
            <Head title="Landlord — Users" />

            <div className="animate-fade-up mb-4 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconUsers size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">Users</h1>
                    <p className="max-w-2xl text-sm text-slate-500">
                        Everyone in every workspace ({users.total}). To help someone, log in as them: a red banner shows the whole time, and every change is
                        recorded under your name.
                    </p>
                </div>
            </div>

            <form onSubmit={submit} className="mb-4 flex max-w-md gap-2">
                <input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search name, email or username"
                    aria-label="Search users"
                    className="w-full rounded-lg border-0 px-3.5 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600"
                />
                <Button type="submit" variant="secondary">
                    Search
                </Button>
            </form>

            {users.data.length === 0 ? (
                <EmptyState title="No users found" description={filters.search ? 'Try a different search.' : undefined} />
            ) : (
                <>
                    <Card>
                        <Table>
                            <TableHead>
                                <tr>
                                    <Th>Name</Th>
                                    <Th>Workspace</Th>
                                    <Th>Role</Th>
                                    <Th>
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                </tr>
                            </TableHead>
                            <TableBody>
                                {users.data.map((user) => (
                                    <tr key={`${user.uuid}-${user.workspace}`}>
                                        <Td>
                                            <p className="font-medium text-slate-900">{user.name}</p>
                                            <p className="text-xs text-slate-500">{user.email}</p>
                                        </Td>
                                        <Td>{user.workspace}</Td>
                                        <Td>
                                            <Badge tone={user.status === 'active' ? 'slate' : 'yellow'}>{user.status === 'active' ? user.role : `${user.role} · inactive`}</Badge>
                                        </Td>
                                        <Td className="text-right">
                                            {user.can_impersonate ? (
                                                <Button type="button" variant="secondary" onClick={() => impersonate(user)}>
                                                    Log in as
                                                </Button>
                                            ) : (
                                                <span className="text-xs text-slate-400">Not available</span>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                    <div className="mt-4">
                        <Pagination links={users.links} />
                    </div>
                </>
            )}
        </LandlordLayout>
    );
}
