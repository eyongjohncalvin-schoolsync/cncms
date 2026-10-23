import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    IconDeviceDesktop,
    IconDeviceMobile,
    IconKey,
    IconLock,
    IconMail,
    IconShieldCheck,
    IconShieldLock,
    IconUserCircle,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';

interface AccountProps {
    account: { name: string; username: string; email: string; locale: string; pending_email: string | null };
    twoFactor: {
        enabled: boolean;
        recovery_codes_remaining: number;
        setup: { secret: string; otpauth_url: string; qr_code: string } | null;
    };
    recoveryCodes: string[] | null;
    sessions: { ip_address: string | null; user_agent: string | null; last_active_at: string; is_current: boolean }[];
    devices: { id: number; name: string; last_used_at: string | null; created_at: string | null; is_current: boolean }[];
    isImpersonating: boolean;
    /** Rendered at /landlord/account (landlord shell) or /account (workspace shell). */
    area: 'landlord' | 'workspace';
}

/**
 * "My account" (App\Http\Controllers\AccountController): the signed-in
 * user's own profile, email, password, authenticator-app two-factor and
 * signed-in sessions/devices. Read-only while a landlord impersonates.
 */
export default function AccountShow(props: AccountProps) {
    const { t } = useTranslation();
    const locked = props.isImpersonating;
    // A landlord without a workspace (or one managing the platform) gets the
    // same page inside the landlord shell — no workspace needed.
    const Layout = props.area === 'landlord' ? LandlordLayout : AppLayout;
    const breadcrumbs =
        props.area === 'landlord'
            ? [{ label: 'Landlord', href: '/landlord' }, { label: t('account.title') }]
            : [{ label: t('account.title') }];

    return (
        <Layout title={t('account.title')} breadcrumbs={breadcrumbs}>
            <Head title={t('account.title')} />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                {locked && (
                    <div className="rounded-md border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        {t('account.impersonating_notice')}
                    </div>
                )}

                {props.recoveryCodes && props.recoveryCodes.length > 0 && <RecoveryCodesPanel codes={props.recoveryCodes} />}

                <ProfileSection account={props.account} locked={locked} />
                <EmailSection account={props.account} locked={locked} />
                <PasswordSection locked={locked} />
                <TwoFactorSection twoFactor={props.twoFactor} locked={locked} />
                <SessionsSection sessions={props.sessions} devices={props.devices} locked={locked} />
            </div>
        </Layout>
    );
}

function Section({ icon, title, description, children }: { icon: ReactNode; title: string; description: string; children: ReactNode }) {
    return (
        <Card>
            <CardHeader className="flex items-start gap-3">
                <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">{icon}</span>
                <div>
                    <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                    <p className="text-sm text-slate-500">{description}</p>
                </div>
            </CardHeader>
            <CardBody>{children}</CardBody>
        </Card>
    );
}

function ProfileSection({ account, locked }: { account: AccountProps['account']; locked: boolean }) {
    const { t } = useTranslation();
    const { data, setData, patch, processing, errors } = useForm({
        name: account.name,
        username: account.username,
        locale: account.locale,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        patch('/account/profile', { preserveScroll: true });
    }

    return (
        <Section icon={<IconUserCircle size={18} stroke={1.75} />} title={t('account.profile')} description={t('account.profile_help')}>
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <TextInput id="name" label={t('account.name')} value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} disabled={locked} required />
                <TextInput id="username" label={t('account.username')} value={data.username} onChange={(e) => setData('username', e.target.value)} error={errors.username} disabled={locked} required />
                <SelectInput id="locale" label={t('common.language')} value={data.locale} onChange={(e) => setData('locale', e.target.value)} error={errors.locale} disabled={locked}>
                    <option value="en">English</option>
                    <option value="fr">Français</option>
                </SelectInput>
                <div className="flex items-end justify-end sm:col-span-2">
                    <Button type="submit" disabled={processing || locked}>
                        {t('common.save')}
                    </Button>
                </div>
            </form>
        </Section>
    );
}

function EmailSection({ account, locked }: { account: AccountProps['account']; locked: boolean }) {
    const { t } = useTranslation();
    const request = useForm({ email: '', current_password: '' });
    const confirm = useForm({ code: '' });

    function submitRequest(e: FormEvent) {
        e.preventDefault();
        request.post('/account/email', { preserveScroll: true, onSuccess: () => request.reset() });
    }

    function submitConfirm(e: FormEvent) {
        e.preventDefault();
        confirm.post('/account/email/confirm', { preserveScroll: true, onSuccess: () => confirm.reset() });
    }

    return (
        <Section icon={<IconMail size={18} stroke={1.75} />} title={t('account.email')} description={t('account.email_help')}>
            <p className="mb-4 text-sm text-slate-700">
                {t('account.current_email')} <span className="font-semibold">{account.email}</span>
            </p>

            {account.pending_email ? (
                <form onSubmit={submitConfirm} className="flex flex-col gap-4">
                    <p className="text-sm text-slate-600">{t('account.email_code_sent', { email: account.pending_email })}</p>
                    <TextInput
                        id="email-code"
                        label={t('account.code')}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        value={confirm.data.code}
                        onChange={(e) => confirm.setData('code', e.target.value)}
                        error={confirm.errors.code}
                        disabled={locked}
                        className="max-w-xs font-mono tracking-widest"
                    />
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button type="button" variant="ghost" disabled={locked} onClick={() => router.delete('/account/email', { preserveScroll: true })}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={confirm.processing || locked}>
                            {t('account.confirm_email')}
                        </Button>
                    </div>
                </form>
            ) : (
                <form onSubmit={submitRequest} className="grid gap-4 sm:grid-cols-2">
                    <TextInput
                        id="new-email"
                        type="email"
                        label={t('account.new_email')}
                        value={request.data.email}
                        onChange={(e) => request.setData('email', e.target.value)}
                        error={request.errors.email}
                        disabled={locked}
                    />
                    <PasswordInput
                        id="email-password"
                        label={t('account.current_password')}
                        autoComplete="current-password"
                        value={request.data.current_password}
                        onChange={(e) => request.setData('current_password', e.target.value)}
                        error={request.errors.current_password}
                        disabled={locked}
                    />
                    <div className="flex justify-end sm:col-span-2">
                        <Button type="submit" disabled={request.processing || locked}>
                            {t('account.send_code')}
                        </Button>
                    </div>
                </form>
            )}
        </Section>
    );
}

function PasswordSection({ locked }: { locked: boolean }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors, reset } = useForm({ current_password: '', password: '', password_confirmation: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        put('/account/password', { preserveScroll: true, onFinish: () => reset() });
    }

    return (
        <Section icon={<IconLock size={18} stroke={1.75} />} title={t('account.password')} description={t('account.password_help')}>
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                <PasswordInput id="current_password" label={t('account.current_password')} autoComplete="current-password" value={data.current_password} onChange={(e) => setData('current_password', e.target.value)} error={errors.current_password} disabled={locked} />
                <PasswordInput id="password" label={t('account.new_password')} autoComplete="new-password" value={data.password} onChange={(e) => setData('password', e.target.value)} error={errors.password} disabled={locked} />
                <PasswordInput id="password_confirmation" label={t('account.confirm_password')} autoComplete="new-password" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} error={errors.password_confirmation} disabled={locked} />
                <div className="flex justify-end sm:col-span-3">
                    <Button type="submit" disabled={processing || locked}>
                        {t('account.change_password')}
                    </Button>
                </div>
            </form>
        </Section>
    );
}

function TwoFactorSection({ twoFactor, locked }: { twoFactor: AccountProps['twoFactor']; locked: boolean }) {
    const { t } = useTranslation();
    const confirm = useForm({ code: '' });
    const disable = useForm({ password: '', code: '' });
    const regenerate = useForm({ password: '' });
    const [panel, setPanel] = useState<'none' | 'disable' | 'regenerate'>('none');

    const badge = twoFactor.enabled ? <Badge tone="green">{t('account.on')}</Badge> : <Badge tone="slate">{t('account.off')}</Badge>;

    return (
        <Section icon={<IconShieldLock size={18} stroke={1.75} />} title={t('account.two_factor')} description={t('account.two_factor_help')}>
            <div className="mb-4 flex items-center gap-2 text-sm text-slate-700">
                {t('account.status')} {badge}
                {twoFactor.enabled && (
                    <span className="text-slate-500">· {t('account.recovery_codes_left', { count: twoFactor.recovery_codes_remaining })}</span>
                )}
            </div>

            {!twoFactor.enabled && !twoFactor.setup && (
                <Button disabled={locked} onClick={() => router.post('/account/two-factor', {}, { preserveScroll: true })}>
                    <IconShieldCheck size={16} stroke={1.75} /> {t('account.set_up_two_factor')}
                </Button>
            )}

            {!twoFactor.enabled && twoFactor.setup && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        confirm.post('/account/two-factor/confirm', { preserveScroll: true, onSuccess: () => confirm.reset() });
                    }}
                    className="flex flex-col gap-4"
                >
                    <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-600">
                        <li>{t('account.setup_step_install')}</li>
                        <li>{t('account.setup_step_scan')}</li>
                        <li>{t('account.setup_step_code')}</li>
                    </ol>
                    <div className="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                        <img src={twoFactor.setup.qr_code} alt={t('account.qr_alt')} className="h-44 w-44 rounded-lg border border-slate-200 bg-white p-2" />
                        <div className="min-w-0 text-sm">
                            <p className="text-slate-500">{t('account.setup_key')}</p>
                            <code className="mt-1 block break-all rounded bg-slate-100 px-2 py-1 font-mono text-slate-800">{twoFactor.setup.secret.replace(/(.{4})/g, '$1 ').trim()}</code>
                        </div>
                    </div>
                    <TextInput
                        id="two-factor-code"
                        label={t('account.code')}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        value={confirm.data.code}
                        onChange={(e) => confirm.setData('code', e.target.value)}
                        error={confirm.errors.code}
                        disabled={locked}
                        className="max-w-xs font-mono tracking-widest"
                    />
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button type="button" variant="ghost" disabled={locked} onClick={() => router.delete('/account/two-factor/setup', { preserveScroll: true })}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={confirm.processing || locked}>
                            {t('account.turn_on')}
                        </Button>
                    </div>
                </form>
            )}

            {twoFactor.enabled && (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" disabled={locked} onClick={() => setPanel(panel === 'regenerate' ? 'none' : 'regenerate')}>
                            <IconKey size={16} stroke={1.75} /> {t('account.new_recovery_codes')}
                        </Button>
                        <Button variant="danger" disabled={locked} onClick={() => setPanel(panel === 'disable' ? 'none' : 'disable')}>
                            {t('account.turn_off')}
                        </Button>
                    </div>

                    {panel === 'regenerate' && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                regenerate.post('/account/two-factor/recovery-codes', {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        regenerate.reset();
                                        setPanel('none');
                                    },
                                });
                            }}
                            className="flex flex-col gap-3 rounded-lg bg-slate-50 p-4"
                        >
                            <p className="text-sm text-slate-600">{t('account.regenerate_help')}</p>
                            <PasswordInput id="regenerate-password" label={t('account.current_password')} value={regenerate.data.password} onChange={(e) => regenerate.setData('password', e.target.value)} error={regenerate.errors.password} />
                            <div className="flex justify-end">
                                <Button type="submit" disabled={regenerate.processing}>{t('account.new_recovery_codes')}</Button>
                            </div>
                        </form>
                    )}

                    {panel === 'disable' && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                disable.delete('/account/two-factor', {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        disable.reset();
                                        setPanel('none');
                                    },
                                });
                            }}
                            className="grid gap-3 rounded-lg bg-red-50/60 p-4 sm:grid-cols-2"
                        >
                            <p className="text-sm text-slate-600 sm:col-span-2">{t('account.disable_help')}</p>
                            <PasswordInput id="disable-password" label={t('account.current_password')} value={disable.data.password} onChange={(e) => disable.setData('password', e.target.value)} error={disable.errors.password} />
                            <TextInput id="disable-code" label={t('account.code_or_recovery')} autoComplete="one-time-code" value={disable.data.code} onChange={(e) => disable.setData('code', e.target.value)} error={disable.errors.code} className="font-mono" />
                            <div className="flex justify-end sm:col-span-2">
                                <Button type="submit" variant="danger" disabled={disable.processing}>{t('account.turn_off')}</Button>
                            </div>
                        </form>
                    )}
                </div>
            )}
        </Section>
    );
}

function RecoveryCodesPanel({ codes }: { codes: string[] }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);

    function copy() {
        void navigator.clipboard?.writeText(codes.join('\n')).then(() => setCopied(true));
    }

    return (
        <Card className="border-amber-300 bg-amber-50/60">
            <CardBody>
                <h2 className="text-base font-semibold text-amber-900">{t('account.recovery_codes_title')}</h2>
                <p className="mt-1 text-sm text-amber-800">{t('account.recovery_codes_help')}</p>
                <ul className="mt-4 grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4">
                    {codes.map((code) => (
                        <li key={code} className="rounded bg-white px-2 py-1.5 text-center text-slate-800 ring-1 ring-amber-200">
                            {code}
                        </li>
                    ))}
                </ul>
                <div className="mt-4 flex justify-end">
                    <Button variant="secondary" onClick={copy}>{copied ? t('account.copied') : t('account.copy_codes')}</Button>
                </div>
            </CardBody>
        </Card>
    );
}

function describeAgent(userAgent: string | null): string {
    if (!userAgent) {
        return '—';
    }

    const browser = /Edg\//.test(userAgent) ? 'Edge' : /Chrome\//.test(userAgent) ? 'Chrome' : /Firefox\//.test(userAgent) ? 'Firefox' : /Safari\//.test(userAgent) ? 'Safari' : null;
    const os = /Windows/.test(userAgent) ? 'Windows' : /Android/.test(userAgent) ? 'Android' : /iPhone|iPad/.test(userAgent) ? 'iOS' : /Mac OS/.test(userAgent) ? 'macOS' : /Linux/.test(userAgent) ? 'Linux' : null;

    return [browser, os].filter(Boolean).join(' · ') || userAgent.slice(0, 60);
}

function formatWhen(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

function SessionsSection({ sessions, devices, locked }: { sessions: AccountProps['sessions']; devices: AccountProps['devices']; locked: boolean }) {
    const { t } = useTranslation();
    const form = useForm({ password: '' });
    const [open, setOpen] = useState(false);

    return (
        <Section icon={<IconDeviceDesktop size={18} stroke={1.75} />} title={t('account.sessions')} description={t('account.sessions_help')}>
            <ul className="divide-y divide-slate-100">
                {sessions.map((session, index) => (
                    <li key={`s-${index}`} className="flex items-center gap-3 py-2.5 text-sm">
                        <IconDeviceDesktop size={18} stroke={1.5} className="shrink-0 text-slate-400" />
                        <div className="min-w-0 flex-1">
                            <p className="font-medium text-slate-800">
                                {describeAgent(session.user_agent)}
                                {session.is_current && <span className="ml-2 text-xs font-semibold text-green-600">{t('account.this_session')}</span>}
                            </p>
                            <p className="text-xs text-slate-500">
                                {session.ip_address ?? '—'} · {t('account.last_active', { when: formatWhen(session.last_active_at) })}
                            </p>
                        </div>
                    </li>
                ))}
                {devices.map((device) => (
                    <li key={`d-${device.id}`} className="flex items-center gap-3 py-2.5 text-sm">
                        <IconDeviceMobile size={18} stroke={1.5} className="shrink-0 text-slate-400" />
                        <div className="min-w-0 flex-1">
                            <p className="font-medium text-slate-800">{device.name === 'api' ? t('account.mobile_app') : device.name}</p>
                            <p className="text-xs text-slate-500">
                                {t('account.last_used', { when: formatWhen(device.last_used_at ?? device.created_at) })}
                            </p>
                        </div>
                    </li>
                ))}
                {sessions.length === 0 && devices.length === 0 && <li className="py-2.5 text-sm text-slate-500">{t('account.no_sessions')}</li>}
            </ul>

            <div className="mt-4 flex flex-col gap-3">
                {!open ? (
                    <div className="flex justify-end">
                        <Button variant="secondary" disabled={locked} onClick={() => setOpen(true)}>
                            {t('account.sign_out_others')}
                        </Button>
                    </div>
                ) : (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.delete('/account/sessions', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    setOpen(false);
                                },
                            });
                        }}
                        className="flex flex-col gap-3 rounded-lg bg-slate-50 p-4"
                    >
                        <PasswordInput id="sessions-password" label={t('account.current_password')} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={form.errors.password} />
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>{t('common.cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('account.sign_out_others')}</Button>
                        </div>
                    </form>
                )}
            </div>
        </Section>
    );
}
