import { Form, Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { IconCheck, IconMinus, IconPencil, IconPlus, IconTrash, IconLock } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { UsersControlCenterTabs } from '@/components/users/UsersControlCenterTabs';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { PermissionsByArea, RoleMatrixRow } from '@/types';

export default function UsersControlCenterRoles({
    roles,
    permissionsByArea,
}: {
    roles: RoleMatrixRow[];
    permissionsByArea: PermissionsByArea;
}) {
    const [addOpen, setAddOpen] = useState(false);
    const [editing, setEditing] = useState<RoleMatrixRow | null>(null);

    const areas = useMemo(() => Object.entries(permissionsByArea), [permissionsByArea]);

    function deleteRole(role: RoleMatrixRow) {
        if (role.user_count > 0) {
            return;
        }
        if (confirm(`Delete the “${role.label}” role? This cannot be undone.`)) {
            router.delete(`/users/roles/${role.uuid}`, { preserveScroll: true });
        }
    }

    function grants(role: RoleMatrixRow, permission: string): boolean {
        return role.is_super || role.permissions.includes(permission);
    }

    return (
        <AppLayout title="Users Control Center" breadcrumbs={[{ label: 'Users Control Center', href: '/users' }, { label: 'Roles & permissions' }]}>
            <Head title="Users Control Center — Roles & permissions" />

            <UsersControlCenterTabs active="roles" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 animate-fade-up">
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Roles &amp; permissions</h1>
                    <p className="text-sm text-slate-500">
                        What each role can do. The <span className="font-medium">Owner</span> role always has full
                        access and can't be changed.
                    </p>
                </div>
                <Button onClick={() => setAddOpen(true)}>
                    <IconPlus size={16} stroke={2} />
                    Add role
                </Button>
            </div>

            <Card className="p-0 animate-fade-up [animation-delay:80ms]">
                <Table label="Roles and permissions">
                    <TableHead>
                        <Th className="sticky left-0 z-10 bg-slate-50/80">Permission</Th>
                        {roles.map((role) => (
                            <Th key={role.uuid} className="text-center whitespace-nowrap">
                                <div className="flex flex-col items-center gap-1">
                                    <span className="flex items-center gap-1 text-slate-700 normal-case">
                                        {role.is_super && <IconLock size={12} stroke={2} className="text-slate-400" />}
                                        {role.label}
                                    </span>
                                    <span className="text-[10px] font-normal tracking-normal text-slate-400 normal-case">
                                        {role.is_system ? 'built-in' : 'custom'} · {role.user_count} user{role.user_count === 1 ? '' : 's'}
                                    </span>
                                    <div className="flex items-center gap-1">
                                        {!role.is_super && (
                                            <button
                                                type="button"
                                                onClick={() => setEditing(role)}
                                                aria-label={`Edit ${role.label}`}
                                                className="rounded p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
                                            >
                                                <IconPencil size={14} stroke={1.75} />
                                            </button>
                                        )}
                                        {!role.is_system && (
                                            <button
                                                type="button"
                                                onClick={() => deleteRole(role)}
                                                disabled={role.user_count > 0}
                                                title={role.user_count > 0 ? 'Reassign its members before deleting' : `Delete ${role.label}`}
                                                aria-label={`Delete ${role.label}`}
                                                className="rounded p-1 text-red-400 transition-colors hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent"
                                            >
                                                <IconTrash size={14} stroke={1.75} />
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </Th>
                        ))}
                    </TableHead>
                    <TableBody>
                        {areas.map(([area, permissions]) => (
                            <PermissionRows
                                key={area}
                                area={area}
                                permissions={permissions}
                                roles={roles}
                                grants={grants}
                                colCount={roles.length + 1}
                            />
                        ))}
                    </TableBody>
                </Table>
            </Card>

            <AddRoleModal open={addOpen} onClose={() => setAddOpen(false)} roles={roles} />

            <EditRoleModal
                role={editing}
                permissionsByArea={permissionsByArea}
                onClose={() => setEditing(null)}
            />
        </AppLayout>
    );
}

function PermissionRows({
    area,
    permissions,
    roles,
    grants,
    colCount,
}: {
    area: string;
    permissions: { value: string; label: string }[];
    roles: RoleMatrixRow[];
    grants: (role: RoleMatrixRow, permission: string) => boolean;
    colCount: number;
}) {
    return (
        <>
            <tr className="bg-slate-50/60">
                <td colSpan={colCount} className="px-4 py-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    {area}
                </td>
            </tr>
            {permissions.map((permission) => (
                <tr key={permission.value} className="transition-colors hover:bg-slate-50">
                    <Td className="sticky left-0 z-10 bg-white">
                        <span className="font-medium text-slate-800">{permission.label}</span>
                        <span className="ml-2 text-xs text-slate-400">{permission.value}</span>
                    </Td>
                    {roles.map((role) => (
                        <td key={role.uuid} className="px-4 py-3 text-center">
                            {grants(role, permission.value) ? (
                                <IconCheck size={16} stroke={2.5} className="mx-auto text-green-600" aria-label="granted" />
                            ) : (
                                <IconMinus size={14} stroke={2} className="mx-auto text-slate-300" aria-label="not granted" />
                            )}
                        </td>
                    ))}
                </tr>
            ))}
        </>
    );
}

/** Common job titles at a cable-TV operator, offered as one-click display names. */
const ROLE_LABEL_SUGGESTIONS = [
    'IT Technician',
    'Base Technician',
    'Fiber Technician',
    'Field Technician',
    'Installer',
    'Human Resource',
    'Accountant',
    'Secretary',
    'Supervisor',
    'Branch Manager',
    'Customer Service',
    'Collector',
    'Storekeeper',
    'Driver',
];

/** Keys of the built-in roles — mirrors App\Models\Role::RESERVED_NAMES. */
const RESERVED_ROLE_NAMES = ['super', 'admin', 'manager', 'agent', 'cashier', 'worker'];

const ROLE_NAME_MAX_LENGTH = 50;

/**
 * Preview of the key the server will generate (Role::generateUniqueName):
 * ASCII slug with underscores, cut to 50 chars, `_2`, `_3`… when taken.
 * Display only — the server is the source of truth and ignores any key
 * the client sends.
 */
function previewRoleKey(label: string, takenNames: string[]): string {
    const base =
        label
            .normalize('NFKD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/@/g, '_at_')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '') || 'role';

    const fit = (suffix: string) => {
        const trimmed = base.slice(0, ROLE_NAME_MAX_LENGTH - suffix.length).replace(/_+$/, '');
        return (trimmed || 'role') + suffix;
    };

    const taken = new Set([...RESERVED_ROLE_NAMES, ...takenNames]);
    let candidate = fit('');
    for (let counter = 2; taken.has(candidate); counter++) {
        candidate = fit(`_${counter}`);
    }

    return candidate;
}

function AddRoleModal({ open, onClose, roles }: { open: boolean; onClose: () => void; roles: RoleMatrixRow[] }) {
    return (
        <Modal open={open} onClose={onClose} title="Add a custom role">
            {/* Inside the Modal so the typed label resets every time it closes. */}
            <AddRoleForm onClose={onClose} roles={roles} />
        </Modal>
    );
}

function AddRoleForm({ onClose, roles }: { onClose: () => void; roles: RoleMatrixRow[] }) {
    const [label, setLabel] = useState('');

    const existingLabels = useMemo(() => new Set(roles.map((r) => r.label.trim().toLowerCase())), [roles]);
    const suggestions = ROLE_LABEL_SUGGESTIONS.filter((s) => !existingLabels.has(s.toLowerCase()));
    const keyPreview = label.trim() === '' ? null : previewRoleKey(label, roles.map((r) => r.name));

    return (
        <Form action="/users/roles" method="post" onSuccess={onClose} className="flex flex-col gap-4">
            {({ errors, processing }) => (
                <>
                    <div className="flex flex-col gap-2">
                        <TextInput
                            id="role_label"
                            name="label"
                            label="Display name"
                            placeholder="e.g. IT Technician"
                            value={label}
                            onChange={(e) => setLabel(e.target.value)}
                            maxLength={100}
                            autoComplete="off"
                            error={errors.label}
                            required
                            aria-describedby={suggestions.length > 0 ? 'role_label_key role_label_suggestions' : 'role_label_key'}
                            className="rounded-lg px-3.5 py-2.5"
                        />
                        <p id="role_label_key" className="text-xs text-slate-400" aria-live="polite">
                            {keyPreview ? (
                                <>
                                    Key (automatic, permanent):{' '}
                                    <code className="rounded bg-slate-100 px-1 py-0.5 text-slate-600">{keyPreview}</code>
                                </>
                            ) : (
                                'Pick a suggestion or type your own. The key is generated automatically.'
                            )}
                        </p>
                        {suggestions.length > 0 && (
                            <div id="role_label_suggestions" role="group" aria-label="Suggested display names" className="flex flex-wrap gap-1.5">
                                {suggestions.map((suggestion) => {
                                    const isPicked = suggestion === label.trim();
                                    return (
                                        <button
                                            key={suggestion}
                                            type="button"
                                            onClick={() => setLabel(suggestion)}
                                            aria-pressed={isPicked}
                                            className={`rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 ${
                                                isPicked
                                                    ? 'bg-blue-50 text-blue-700 ring-blue-300'
                                                    : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50 hover:text-slate-800'
                                            }`}
                                        >
                                            {suggestion}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                    <TextInput
                        id="role_description"
                        name="description"
                        label="Description (optional)"
                        error={errors.description}
                        className="rounded-lg px-3.5 py-2.5"
                    />
                    <SelectInput
                        id="role_clone_from"
                        name="clone_from"
                        label="Start from (optional)"
                        defaultValue=""
                        error={errors.clone_from}
                        className="rounded-lg px-3.5 py-2.5"
                    >
                        <option value="">No permissions — start empty</option>
                        {roles
                            .filter((r) => !r.is_super)
                            .map((r) => (
                                <option key={r.uuid} value={r.uuid}>
                                    Copy from {r.label}
                                </option>
                            ))}
                    </SelectInput>

                    <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing} className="font-semibold">
                            {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                            {processing ? 'Creating…' : 'Create role'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function EditRoleModal({
    role,
    permissionsByArea,
    onClose,
}: {
    role: RoleMatrixRow | null;
    permissionsByArea: PermissionsByArea;
    onClose: () => void;
}) {
    const [selected, setSelected] = useState<string[]>([]);

    // Re-seed the checklist each time a different role's modal opens.
    useEffect(() => {
        setSelected(role?.permissions ?? []);
    }, [role]);

    if (!role) {
        return null;
    }

    function toggle(permission: string, checked: boolean) {
        setSelected((prev) => (checked ? [...new Set([...prev, permission])] : prev.filter((p) => p !== permission)));
    }

    return (
        <Modal open={role !== null} onClose={onClose} title={`Edit “${role.label}”`}>
            <Form
                key={role.uuid}
                action={`/users/roles/${role.uuid}`}
                method="patch"
                transform={(data) => ({ ...data, permissions: selected })}
                onSuccess={onClose}
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex items-center gap-2 text-xs text-slate-500">
                            <Badge tone="slate">{role.name}</Badge>
                            {role.is_system ? 'built-in role — key is permanent' : 'custom role — key is permanent'}
                        </div>

                        <TextInput
                            id="edit_role_label"
                            name="label"
                            label="Display name"
                            defaultValue={role.label}
                            error={errors.label}
                            required
                            className="rounded-lg px-3.5 py-2.5"
                        />
                        <TextInput
                            id="edit_role_description"
                            name="description"
                            label="Description (optional)"
                            defaultValue={role.description ?? ''}
                            error={errors.description}
                            className="rounded-lg px-3.5 py-2.5"
                        />

                        {errors.permissions && <p className="text-xs text-red-600">{errors.permissions}</p>}

                        <div className="max-h-[45vh] overflow-y-auto rounded-lg border border-slate-200 p-3">
                            {Object.entries(permissionsByArea).map(([area, permissions]) => (
                                <fieldset key={area} className="mb-3 last:mb-0">
                                    <legend className="mb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                        {area}
                                    </legend>
                                    <div className="grid gap-1.5 sm:grid-cols-2">
                                        {permissions.map((permission) => (
                                            <label
                                                key={permission.value}
                                                className="flex items-center gap-2 rounded px-1.5 py-1 text-sm text-slate-700 hover:bg-slate-50"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={selected.includes(permission.value)}
                                                    onChange={(e) => toggle(permission.value, e.target.checked)}
                                                    className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                                />
                                                <span>
                                                    {permission.label}
                                                    <span className="ml-1.5 text-xs text-slate-400">{permission.value}</span>
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            ))}
                        </div>

                        <div className="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
                            <span className="text-xs text-slate-400">{selected.length} selected</span>
                            <div className="flex items-center gap-3">
                                <Button type="button" variant="secondary" onClick={onClose}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing} className="font-semibold">
                                    {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                    {processing ? 'Saving…' : 'Save role'}
                                </Button>
                            </div>
                        </div>
                    </>
                )}
            </Form>
        </Modal>
    );
}
