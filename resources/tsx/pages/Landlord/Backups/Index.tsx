import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { IconDatabase, IconDownload } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { xsrfTokenHeader } from '@/lib/csrfToken';

interface Backup {
    name: string;
    bytes: number;
    created_at: string;
}

function formatSize(bytes: number): string {
    return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function formatWhen(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * The server's database backups (App\Http\Controllers\Landlord\
 * BackupController): the nightly and pre-deploy dumps, "Back up now", and a
 * download locked with a password the landlord chooses.
 */
export default function LandlordBackupsIndex({
    backups,
    is_stale,
    is_running,
    stale_hours,
    has_two_factor,
}: {
    backups: Backup[];
    is_stale: boolean;
    is_running: boolean;
    stale_hours: number;
    has_two_factor: boolean;
}) {
    const [starting, setStarting] = useState(false);
    const [downloading, setDownloading] = useState<Backup | null>(null);

    function backUpNow() {
        setStarting(true);
        router.post('/landlord/backups', {}, { preserveScroll: true, onFinish: () => setStarting(false) });
    }

    return (
        <LandlordLayout title="Backups" breadcrumbs={[{ label: 'Landlord' }, { label: 'Backups' }]}>
            <Head title="Landlord — Backups" />

            <div className="animate-fade-up mb-6 flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                        <IconDatabase size={20} stroke={1.75} />
                    </span>
                    <div>
                        <h1 className="font-display text-2xl font-semibold text-slate-900">Database backups</h1>
                        <p className="max-w-2xl text-sm text-slate-500">
                            Made every night and before every deploy, kept 14 days, and copied off the server. Each file is the whole database — every workspace.
                        </p>
                    </div>
                </div>
                <Button type="button" variant="secondary" onClick={backUpNow} disabled={starting || is_running}>
                    {(starting || is_running) && <LoadingSpinner />}
                    {is_running ? 'Backing up…' : 'Back up now'}
                </Button>
            </div>

            {is_stale && (
                <p className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    No backup in the last {stale_hours} hours — the nightly backup may have stopped. Check the server's cron, then use Back up now.
                </p>
            )}
            {!has_two_factor && (
                <p className="mb-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Downloading needs two-step login on your account.{' '}
                    <Link href="/landlord/account" className="font-medium underline">
                        Turn it on in My account
                    </Link>
                    .
                </p>
            )}

            {backups.length === 0 ? (
                <p className="text-sm text-slate-500">No backups on the server yet.</p>
            ) : (
                <Card className="animate-fade-up">
                    <Table>
                        <TableHead>
                            <tr>
                                <Th>Made</Th>
                                <Th>File</Th>
                                <Th>Size</Th>
                                <Th />
                            </tr>
                        </TableHead>
                        <TableBody>
                            {backups.map((backup) => (
                                <tr key={backup.name}>
                                    <Td>{formatWhen(backup.created_at)}</Td>
                                    <Td className="font-mono text-xs">{backup.name}</Td>
                                    <Td>{formatSize(backup.bytes)}</Td>
                                    <Td className="text-right">
                                        <Button type="button" variant="secondary" disabled={!has_two_factor} onClick={() => setDownloading(backup)}>
                                            <IconDownload size={16} />
                                            Download
                                        </Button>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            <DownloadModal backup={downloading} onClose={() => setDownloading(null)} />
        </LandlordLayout>
    );
}

type DownloadErrors = Partial<Record<'password' | 'zip_password' | 'general', string>>;

/**
 * A plain fetch(), not Inertia: the answer is a file, saved through a blob
 * link; validation errors come back as 422 JSON.
 */
function DownloadModal({ backup, onClose }: { backup: Backup | null; onClose: () => void }) {
    const [password, setPassword] = useState('');
    const [zipPassword, setZipPassword] = useState('');
    const [zipPasswordConfirmation, setZipPasswordConfirmation] = useState('');
    const [errors, setErrors] = useState<DownloadErrors>({});
    const [processing, setProcessing] = useState(false);

    function close() {
        setPassword('');
        setZipPassword('');
        setZipPasswordConfirmation('');
        setErrors({});
        onClose();
    }

    async function submit(event: FormEvent) {
        event.preventDefault();

        if (!backup) {
            return;
        }

        setProcessing(true);
        setErrors({});

        try {
            const response = await fetch(`/landlord/backups/${encodeURIComponent(backup.name)}/download`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfTokenHeader() },
                body: JSON.stringify({ password, zip_password: zipPassword, zip_password_confirmation: zipPasswordConfirmation }),
            });

            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                const fieldErrors = (body.errors ?? {}) as Record<string, string[]>;
                setErrors({
                    password: fieldErrors.password?.[0],
                    zip_password: fieldErrors.zip_password?.[0],
                    general:
                        Object.keys(fieldErrors).length === 0
                            ? response.status === 429
                                ? 'Too many attempts — wait a minute and try again.'
                                : (body.message ?? 'The download failed.')
                            : undefined,
                });

                return;
            }

            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = backup.name.replace(/\.sql\.gz$/, '.zip');
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            close();
        } catch {
            setErrors({ general: 'The download failed — check your connection and try again.' });
        } finally {
            setProcessing(false);
        }
    }

    return (
        <Modal open={backup !== null} onClose={close} title="Download backup">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    <span className="font-mono text-xs">{backup?.name}</span> holds every workspace's customers, payments and password hashes. It downloads as a zip locked
                    with the password you choose here — open it with 7-Zip. Every landlord is emailed about this download.
                </p>

                <PasswordInput
                    id="password"
                    label="Your account password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    error={errors.password}
                    required
                />
                <PasswordInput
                    id="zip_password"
                    label="Password to lock the file (12+ characters)"
                    autoComplete="new-password"
                    value={zipPassword}
                    onChange={(event) => setZipPassword(event.target.value)}
                    error={errors.zip_password}
                    required
                />
                <PasswordInput
                    id="zip_password_confirmation"
                    label="Same file password again"
                    autoComplete="new-password"
                    value={zipPasswordConfirmation}
                    onChange={(event) => setZipPasswordConfirmation(event.target.value)}
                    required
                />
                <p className="text-xs text-slate-500">Keep the file password in your password manager. Without it the zip cannot be opened.</p>

                {errors.general && <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{errors.general}</p>}

                <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button type="button" variant="secondary" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoadingSpinner className="text-white" />}
                        Download locked zip
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
