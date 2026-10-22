import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { IconShieldLock } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { PageProps } from '@/types';

interface Landlord {
    uuid: string;
    name: string;
    email: string;
    granted_by: string | null;
    granted_at: string | null;
}

/**
 * Who has platform landlord access, with grant/revoke
 * (App\Http\Controllers\Landlord\LandlordController).
 */
export default function LandlordLandlordsIndex({ landlords }: { landlords: Landlord[] }) {
    const { auth } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({ email: '' });

    function grant(event: FormEvent) {
        event.preventDefault();
        post('/landlord/landlords', { preserveScroll: true, onSuccess: () => reset() });
    }

    function revoke(landlord: Landlord) {
        if (confirm(`Revoke landlord access from ${landlord.name}? They keep their account and workspace access.`)) {
            router.delete(`/landlord/landlords/${landlord.uuid}`, { preserveScroll: true });
        }
    }

    return (
        <LandlordLayout title="Landlords" breadcrumbs={[{ label: 'Landlord', href: '/landlord' }, { label: 'Landlords' }]}>
            <Head title="Landlord — Landlords" />

            <div className="animate-fade-up mb-6 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconShieldLock size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">Landlords</h1>
                    <p className="max-w-2xl text-sm text-slate-500">
                        Landlords manage every workspace on the platform and keep access during maintenance. Grant it sparingly.
                    </p>
                </div>
            </div>

            <Card className="animate-fade-up mb-6 max-w-xl" style={{ animationDelay: '60ms' }}>
                <CardHeader>
                    <p className="font-medium text-slate-900">Grant landlord access</p>
                </CardHeader>
                <CardBody>
                    <form onSubmit={grant} className="flex flex-wrap items-end gap-3">
                        <div className="min-w-64 flex-1">
                            <TextInput
                                id="email"
                                type="email"
                                label="Email of an existing user"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                error={errors.email}
                                required
                            />
                        </div>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoadingSpinner className="text-white" />}
                            Grant access
                        </Button>
                    </form>
                </CardBody>
            </Card>

            <Card className="animate-fade-up" style={{ animationDelay: '120ms' }}>
                <Table>
                    <TableHead>
                        <tr>
                            <Th>Name</Th>
                            <Th>Email</Th>
                            <Th>Granted</Th>
                            <Th>
                                <span className="sr-only">Actions</span>
                            </Th>
                        </tr>
                    </TableHead>
                    <TableBody>
                        {landlords.map((landlord) => (
                            <tr key={landlord.uuid}>
                                <Td className="font-medium text-slate-900">{landlord.name}</Td>
                                <Td>{landlord.email}</Td>
                                <Td className="text-sm text-slate-500">
                                    {landlord.granted_at ? new Date(landlord.granted_at).toLocaleDateString() : '—'}
                                    {' · '}
                                    {landlord.granted_by ?? 'command line'}
                                </Td>
                                <Td className="text-right">
                                    {landlord.email === auth.user?.email ? (
                                        <span className="text-xs text-slate-400">You</span>
                                    ) : (
                                        <Button type="button" variant="ghost" onClick={() => revoke(landlord)} disabled={landlords.length <= 1}>
                                            Revoke
                                        </Button>
                                    )}
                                </Td>
                            </tr>
                        ))}
                    </TableBody>
                </Table>
            </Card>
        </LandlordLayout>
    );
}
