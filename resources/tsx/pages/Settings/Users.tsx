import { Form, Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { IconEdit, IconInfoCircle, IconUsersGroup } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { RoleBadge } from '@/components/shared/StatusBadge';
import type { Branch, Role, TenantUserRow } from '@/types';

const ROLES: Role[] = ['super', 'admin', 'manager', 'agent', 'worker'];

// Free-text convenience only (see the tenant_users migration's doc block) —
// paired with a plain <input list="..."> so admins can still type any job
// title an operator actually uses. Not an enum, never validated against.
const JOB_TITLE_SUGGESTIONS = [
    'Technician',
    'Secretary',
    'Manager',
    'Recovery Agent',
    'Recovery Coordinator',
    'Sales Manager',
    'IT Technician',
    'Field Agent',
    'Billing Clerk',
    'Customer Service Representative',
    'Network Technician',
    'Accountant',
    'Store Keeper',
    'Driver',
];

const JOB_TITLE_DATALIST_ID = 'job-title-suggestions';

function initials(name: string): string {
    const parts = name.trim().split(/\s+/);
    const first = parts[0]?.[0] ?? '';
    const last = parts.length > 1 ? parts[parts.length - 1][0] : '';
    return `${first}${last}`.toUpperCase() || '?';
}

export default function SettingsUsers({ users, branches }: { users: TenantUserRow[]; branches: Branch[] }) {
    const showBranchControls = branches.length > 1;
    const [createOpen, setCreateOpen] = useState(false);
    const [editingUser, setEditingUser] = useState<TenantUserRow | null>(null);
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', () => setIsLoading(true));
        const removeFinish = router.on('finish', () => setIsLoading(false));

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    function changeRole(tenantUser: TenantUserRow, role: string) {
        if (role === tenantUser.role) {
            return;
        }

        router.patch(`/settings/users/${tenantUser.id}`, { role }, { preserveScroll: true });
    }

    function changeBranch(tenantUser: TenantUserRow, branchUuid: string) {
        if (branchUuid === (tenantUser.branch_uuid ?? '')) {
            return;
        }

        // Empty option value = clear back to unrestricted (branch_id: null).
        router.patch(
            `/settings/users/${tenantUser.id}`,
            { branch_uuid: branchUuid || null },
            { preserveScroll: true },
        );
    }

    // Narrow per-user payment-recording grant — only ever shown/toggled for
    // a worker-role row (see PaymentPolicy::create()'s doc comment). Patches
    // this one field alone, same "each control patches its own field"
    // pattern as changeRole()/changeBranch() above.
    function toggleCanRecordPayments(tenantUser: TenantUserRow, checked: boolean) {
        router.patch(`/settings/users/${tenantUser.id}`, { can_record_payments: checked }, { preserveScroll: true });
    }

    // Investor tier grant — see app/Policies/ReportPolicy.php's view() doc
    // comment and references/rbac-permissions.md section 7. Unlike
    // toggleCanRecordPayments above, this is deliberately NOT restricted to
    // one role: it's a pure additive OR (view /reports only) that makes
    // sense to grant on any role, so the checkbox below renders for every
    // row rather than being conditional. Same "each control patches its
    // own field" pattern as the other toggles here.
    function toggleIsInvestor(tenantUser: TenantUserRow, checked: boolean) {
        router.patch(`/settings/users/${tenantUser.id}`, { is_investor: checked }, { preserveScroll: true });
    }

    function deactivate(tenantUser: TenantUserRow) {
        if (confirm(`Deactivate ${tenantUser.name}? They will no longer be able to sign in.`)) {
            router.post(`/settings/users/${tenantUser.id}/deactivate`, {}, { preserveScroll: true });
        }
    }

    return (
        <AppLayout
            title="Users & Roles"
            breadcrumbs={[{ label: 'Settings', href: '/settings/company' }, { label: 'Users' }]}
        >
            <Head title="Settings — Users & Roles" />

            <SettingsTabs active="users" />

            {/* Shared by both the Add User and Edit Job Title forms below. */}
            <datalist id={JOB_TITLE_DATALIST_ID}>
                {JOB_TITLE_SUGGESTIONS.map((title) => (
                    <option key={title} value={title} />
                ))}
            </datalist>

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 animate-fade-up">
                <div className="flex items-center gap-3">
                    <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 text-purple-700">
                        <IconUsersGroup size={20} stroke={1.75} />
                    </span>
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="font-display text-2xl text-slate-900">Users & Roles</h1>
                            {isLoading && <LoadingSpinner className="text-blue-600" />}
                        </div>
                        <p className="text-sm text-slate-500">Manage workspace staff accounts and permissions.</p>
                    </div>
                </div>
                <Button onClick={() => setCreateOpen(true)}>Add User</Button>
            </div>

            <div
                className="mb-4 flex animate-fade-up items-start gap-2.5 rounded-lg border border-blue-100 bg-blue-50/60 p-3 text-sm text-blue-800 [animation-delay:60ms]"
            >
                <IconInfoCircle size={18} stroke={1.75} className="mt-0.5 shrink-0 text-blue-500" />
                <p>
                    <span className="font-medium">Job title</span> is a free-text label for a person's real-world
                    position (e.g. "Recovery Coordinator") — it's purely descriptive.{' '}
                    <span className="font-medium">Permission role</span> is what actually controls what they can do
                    in the app. Changing one never changes the other.
                </p>
            </div>

            {users.length === 0 ? (
                <EmptyState title="No users found" description="Add a user to get started." />
            ) : (
                <Card className="p-0 animate-fade-up [animation-delay:100ms]">
                    <Table>
                        <TableHead>
                            <Th>Staff</Th>
                            <Th>Username</Th>
                            <Th>Email</Th>
                            <Th>Permission Role</Th>
                            {showBranchControls && <Th>Branch</Th>}
                            <Th>Can Record Payments</Th>
                            <Th>Investor</Th>
                            <Th>Status</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {users.map((tenantUser) => (
                                <tr key={tenantUser.id} className="transition-colors hover:bg-slate-50/70">
                                    <Td>
                                        <div className="flex items-start gap-2.5">
                                            <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">
                                                {initials(tenantUser.name)}
                                            </span>
                                            <div>
                                                <div className="font-medium text-slate-900">
                                                    {tenantUser.name}
                                                    {tenantUser.job_title && (
                                                        <span className="text-slate-500"> — {tenantUser.job_title}</span>
                                                    )}
                                                </div>
                                                <div className="mt-1 flex items-center gap-1.5">
                                                    <RoleBadge role={tenantUser.role} />
                                                    {!tenantUser.job_title && (
                                                        <span className="text-xs text-slate-400">No job title set</span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>{tenantUser.username}</Td>
                                    <Td>{tenantUser.email}</Td>
                                    <Td>
                                        <SelectInput
                                            aria-label={`Permission role for ${tenantUser.name}`}
                                            value={tenantUser.role}
                                            onChange={(e) => changeRole(tenantUser, e.target.value)}
                                            className="py-1.5"
                                        >
                                            {ROLES.map((role) => (
                                                <option key={role} value={role}>
                                                    {role}
                                                </option>
                                            ))}
                                        </SelectInput>
                                    </Td>
                                    {showBranchControls && (
                                        <Td>
                                            {tenantUser.role === 'agent' ? (
                                                <span
                                                    className="text-xs text-slate-400"
                                                    title="Agents are scoped automatically by their assigned zone's branch, not this setting."
                                                >
                                                    Via zone assignment
                                                </span>
                                            ) : (
                                                <SelectInput
                                                    aria-label={`Branch for ${tenantUser.name}`}
                                                    value={tenantUser.branch_uuid ?? ''}
                                                    onChange={(e) => changeBranch(tenantUser, e.target.value)}
                                                    className="py-1.5"
                                                >
                                                    <option value="">All branches</option>
                                                    {branches.map((branch) => (
                                                        <option key={branch.uuid} value={branch.uuid}>
                                                            {branch.name}
                                                        </option>
                                                    ))}
                                                </SelectInput>
                                            )}
                                        </Td>
                                    )}
                                    <Td>
                                        {tenantUser.role === 'worker' ? (
                                            <label className="inline-flex items-center gap-2 text-sm text-slate-700">
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Can record payments — ${tenantUser.name}`}
                                                    checked={tenantUser.can_record_payments}
                                                    onChange={(e) => toggleCanRecordPayments(tenantUser, e.target.checked)}
                                                    className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                                />
                                                Allowed
                                            </label>
                                        ) : (
                                            <span
                                                className="text-xs text-slate-400"
                                                title="Only applicable to the Worker role — every other role already records payments via its permission role."
                                            >
                                                N/A
                                            </span>
                                        )}
                                    </Td>
                                    <Td>
                                        <label className="inline-flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                aria-label={`Investor — ${tenantUser.name}`}
                                                checked={tenantUser.is_investor}
                                                onChange={(e) => toggleIsInvestor(tenantUser, e.target.checked)}
                                                className="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                                            />
                                            Allowed
                                        </label>
                                    </Td>
                                    <Td>
                                        <Badge tone={tenantUser.status === 'active' ? 'green' : 'slate'}>
                                            {tenantUser.status}
                                        </Badge>
                                    </Td>
                                    <Td>
                                        <div className="flex items-center gap-3">
                                            <button
                                                type="button"
                                                onClick={() => setEditingUser(tenantUser)}
                                                className="inline-flex items-center gap-1 rounded text-sm font-medium text-blue-600 transition-colors hover:text-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-1"
                                            >
                                                <IconEdit size={16} stroke={1.75} />
                                                Job Title
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => deactivate(tenantUser)}
                                                disabled={tenantUser.status === 'passive'}
                                                className="rounded-md px-2 py-1 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent"
                                            >
                                                Deactivate
                                            </button>
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Add User">
                <Form
                    action="/settings/users"
                    method="post"
                    resetOnSuccess
                    onSuccess={() => setCreateOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <TextInput
                                id="name"
                                name="name"
                                label="Name"
                                error={errors.name}
                                required
                                className="rounded-lg px-3.5 py-2.5"
                            />
                            <TextInput
                                id="username"
                                name="username"
                                label="Username"
                                error={errors.username}
                                required
                                className="rounded-lg px-3.5 py-2.5"
                            />
                            <TextInput
                                id="email"
                                name="email"
                                type="email"
                                label="Email"
                                error={errors.email}
                                required
                                className="rounded-lg px-3.5 py-2.5"
                            />
                            <TextInput
                                id="password"
                                name="password"
                                type="password"
                                label="Password"
                                error={errors.password}
                                minLength={8}
                                required
                                className="rounded-lg px-3.5 py-2.5"
                            />
                            <SelectInput
                                id="role"
                                name="role"
                                label="Permission Role"
                                defaultValue="agent"
                                error={errors.role}
                                required
                                className="rounded-lg px-3.5 py-2.5"
                            >
                                {ROLES.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </SelectInput>
                            {showBranchControls && (
                                <>
                                    <SelectInput
                                        id="branch_uuid"
                                        name="branch_uuid"
                                        label="Branch (optional)"
                                        defaultValue=""
                                        error={errors.branch_uuid}
                                        className="rounded-lg px-3.5 py-2.5"
                                    >
                                        <option value="">All branches (unrestricted)</option>
                                        {branches.map((branch) => (
                                            <option key={branch.uuid} value={branch.uuid}>
                                                {branch.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <p className="-mt-2 text-xs text-slate-500">
                                        Fences this person to one branch's data only. Leave as "All branches" for
                                        unrestricted access. Ignored for the Agent role — agents are scoped
                                        automatically by their assigned zone's branch instead.
                                    </p>
                                </>
                            )}
                            <TextInput
                                id="job_title"
                                name="job_title"
                                label="Job Title (optional)"
                                placeholder="e.g. Recovery Coordinator"
                                list={JOB_TITLE_DATALIST_ID}
                                error={errors.job_title}
                                className="rounded-lg px-3.5 py-2.5"
                            />
                            <p className="-mt-2 text-xs text-slate-500">
                                A descriptive label only — it does not affect permissions. Free text; pick a
                                suggestion or type your own.
                            </p>

                            <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                                <Button type="button" variant="secondary" onClick={() => setCreateOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing} className="font-semibold">
                                    {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                    {processing ? 'Creating…' : 'Create User'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Modal>

            <Modal open={editingUser !== null} onClose={() => setEditingUser(null)} title="Edit Job Title">
                {editingUser && (
                    <Form
                        key={editingUser.id}
                        action={`/settings/users/${editingUser.id}`}
                        method="patch"
                        onSuccess={() => setEditingUser(null)}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <p className="text-sm text-slate-500">
                                    Updating the descriptive job title for{' '}
                                    <span className="font-medium text-slate-700">{editingUser.name}</span>. Their
                                    permission role (<RoleBadge role={editingUser.role} />) is unaffected — change
                                    that from the Permission Role column instead.
                                </p>
                                <TextInput
                                    id="edit_job_title"
                                    name="job_title"
                                    label="Job Title"
                                    placeholder="e.g. Recovery Coordinator"
                                    list={JOB_TITLE_DATALIST_ID}
                                    defaultValue={editingUser.job_title ?? ''}
                                    error={errors.job_title}
                                    className="rounded-lg px-3.5 py-2.5"
                                />

                                <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                                    <Button type="button" variant="secondary" onClick={() => setEditingUser(null)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing} className="font-semibold">
                                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                        {processing ? 'Saving…' : 'Save'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </Modal>
        </AppLayout>
    );
}
