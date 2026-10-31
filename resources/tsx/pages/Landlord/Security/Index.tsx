import { Head, router } from '@inertiajs/react';
import { IconDeviceMobile, IconLock, IconShieldCheck, IconWorld } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';

interface Person {
    identifier: string;
    user_uuid: string | null;
    user: string | null;
    company: string | null;
}

interface SecurityProps {
    summary: { failures: number; refused_while_blocked: number; ips: number; blocked_now: number };
    blocked: (Person & { failures: number; until: string })[];
    blocked_devices: { ip_address: string; failures: number; accounts: number; until: string }[];
    your_ip: string | null;
    ips: { ip_address: string; attempts: number; accounts: number; last_at: string }[];
    recent: (Person & { id: number; reason: 'password' | 'blocked'; channel: 'web' | 'mobile'; ip_address: string | null; user_agent: string | null; at: string; cleared: boolean })[];
    limits: { window_minutes: number; block_after: number; block_minutes: number; long_block_minutes: number; device_block_after: number; device_block_minutes: number; trusted_ips: string[]; ip_alert_accounts: number };
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function Who({ person }: { person: Person }) {
    return person.user ? (
        <>
            <p className="font-medium text-slate-900">{person.user}</p>
            <p className="text-xs text-slate-500">
                {person.company ?? 'No company'} · typed "{person.identifier}"
            </p>
        </>
    ) : (
        <>
            <p className="font-medium text-slate-900">"{person.identifier}"</p>
            <p className="text-xs text-slate-500">No such account</p>
        </>
    );
}

/**
 * Landlord → Security (Landlord\SecurityController, App\Services\LoginGuard):
 * refused password sign-ins, blocked accounts with Unblock, busiest IPs.
 */
export default function LandlordSecurity({ summary, blocked, blocked_devices, ips, recent, limits, your_ip }: SecurityProps) {
    function unblock(person: Person) {
        if (confirm(`Unblock password sign-in for ${person.user ?? person.identifier}?`)) {
            router.post('/landlord/security/unblock', { user_uuid: person.user_uuid, identifier: person.identifier }, { preserveScroll: true });
        }
    }

    function unblockDevice(ip: string) {
        if (confirm(`Allow password sign-in from ${ip} again?`)) {
            router.post('/landlord/security/unblock-device', { ip_address: ip }, { preserveScroll: true });
        }
    }

    const tiles = [
        { label: 'Wrong passwords (24 h)', value: summary.failures },
        { label: 'Tries while blocked (24 h)', value: summary.refused_while_blocked },
        { label: 'IP addresses (24 h)', value: summary.ips },
        { label: 'Blocked now', value: summary.blocked_now },
    ];

    return (
        <LandlordLayout title="Security" breadcrumbs={[{ label: 'Landlord' }, { label: 'Security' }]}>
            <Head title="Landlord — Security" />

            <div className="animate-fade-up mb-4 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconShieldCheck size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">Security</h1>
                    <p className="text-sm text-slate-500">
                        {limits.block_after} wrong passwords within {limits.window_minutes} minutes block an account's password sign-in for {limits.block_minutes} minutes;
                        another wrong password after that blocks it for {limits.long_block_minutes} minutes. {limits.device_block_after} wrong passwords from one IP address,
                        whatever usernames are typed, block sign-in from that address for {limits.device_block_minutes} minutes. Email-code sign-in keeps working. The
                        owner is emailed; you are emailed when a device is blocked or fails on {limits.ip_alert_accounts}+ accounts.
                    </p>
                    <p className="mt-1 text-xs text-slate-400">
                        Your address as the system sees it: <span className="font-medium text-slate-600">{your_ip ?? 'unknown'}</span>
                        {limits.trusted_ips.length > 0 && <> · never blocked: {limits.trusted_ips.join(', ')}</>}
                    </p>
                </div>
            </div>

            <div className="animate-fade-up mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                {tiles.map((tile) => (
                    <Card key={tile.label}>
                        <CardBody>
                            <p className="text-xs text-slate-500">{tile.label}</p>
                            <p className="mt-1 font-display text-2xl font-semibold tabular-nums text-slate-900">{tile.value.toLocaleString()}</p>
                        </CardBody>
                    </Card>
                ))}
            </div>

            <Card className="animate-fade-up mb-6">
                <CardHeader>
                    <div className="flex items-center gap-2">
                        <IconLock size={18} stroke={1.75} className="text-red-600" />
                        <p className="font-medium text-slate-900">Blocked now</p>
                    </div>
                </CardHeader>
                {blocked.length === 0 ? (
                    <CardBody>
                        <p className="text-sm text-slate-500">No account is blocked.</p>
                    </CardBody>
                ) : (
                    <ul className="divide-y divide-slate-100">
                        {blocked.map((row) => (
                            <li key={`${row.user_uuid ?? ''}${row.identifier}`} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div className="min-w-0">
                                    <Who person={row} />
                                    <p className="text-xs text-red-700">
                                        {row.failures} wrong passwords · blocked until {formatTime(row.until)}
                                    </p>
                                </div>
                                <Button type="button" variant="secondary" onClick={() => unblock(row)}>
                                    Unblock
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {blocked_devices.length > 0 && (
                <Card className="animate-fade-up mb-6">
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <IconWorld size={18} stroke={1.75} className="text-red-600" />
                            <p className="font-medium text-slate-900">Blocked devices</p>
                        </div>
                    </CardHeader>
                    <ul className="divide-y divide-slate-100">
                        {blocked_devices.map((device) => (
                            <li key={device.ip_address} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div className="min-w-0">
                                    <p className="font-medium text-slate-900">{device.ip_address}</p>
                                    <p className="text-xs text-red-700">
                                        {device.failures} wrong passwords on {device.accounts} account{device.accounts === 1 ? '' : 's'} · blocked until {formatTime(device.until)}
                                    </p>
                                </div>
                                <Button type="button" variant="secondary" onClick={() => unblockDevice(device.ip_address)}>
                                    Unblock device
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <Card className="animate-fade-up min-w-0">
                    <CardHeader>
                        <p className="font-medium text-slate-900">Latest refused sign-ins</p>
                    </CardHeader>
                    {recent.length === 0 ? (
                        <CardBody>
                            <p className="text-sm text-slate-500">None yet.</p>
                        </CardBody>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHead>
                                    <Th>When</Th>
                                    <Th>Account</Th>
                                    <Th>Result</Th>
                                    <Th>From</Th>
                                </TableHead>
                                <TableBody>
                                    {recent.map((row) => (
                                        <tr key={row.id}>
                                            <Td className="whitespace-nowrap text-slate-600">{formatTime(row.at)}</Td>
                                            <Td>
                                                <Who person={row} />
                                            </Td>
                                            <Td>
                                                {row.reason === 'blocked' ? (
                                                    <Badge tone="red">Refused: blocked</Badge>
                                                ) : (
                                                    <Badge tone={row.cleared ? 'slate' : 'yellow'}>{row.cleared ? 'Wrong password · cleared' : 'Wrong password'}</Badge>
                                                )}
                                            </Td>
                                            <Td>
                                                <p className="flex items-center gap-1 whitespace-nowrap text-slate-700">
                                                    {row.channel === 'mobile' ? <IconDeviceMobile size={14} /> : <IconWorld size={14} />}
                                                    {row.ip_address ?? '—'}
                                                </p>
                                                {row.user_agent && <p className="max-w-[16rem] truncate text-xs text-slate-400" title={row.user_agent}>{row.user_agent}</p>}
                                            </Td>
                                        </tr>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </Card>

                <Card className="animate-fade-up">
                    <CardHeader>
                        <p className="font-medium text-slate-900">Busiest IP addresses (24 h)</p>
                    </CardHeader>
                    {ips.length === 0 ? (
                        <CardBody>
                            <p className="text-sm text-slate-500">None.</p>
                        </CardBody>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {ips.map((ip) => (
                                <li key={ip.ip_address} className="px-4 py-2.5 text-sm">
                                    <p className="font-medium text-slate-900">{ip.ip_address}</p>
                                    <p className={`text-xs ${ip.accounts >= limits.ip_alert_accounts ? 'text-red-700' : 'text-slate-500'}`}>
                                        {ip.attempts} attempts on {ip.accounts} account{ip.accounts === 1 ? '' : 's'} · last {formatTime(ip.last_at)}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </LandlordLayout>
    );
}
