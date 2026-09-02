import { Form, Head } from '@inertiajs/react';
import {
    IconBellRinging,
    IconBrandWhatsapp,
    IconMail,
    IconMessage,
    IconLock,
    IconCheck,
    IconAlertCircle,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { NotificationSettings } from '@/types';

interface SettingsNotificationsProps {
    settings: NotificationSettings;
    bulk_whatsapp_entitled: boolean;
}

/**
 * Settings — Notifications. Per-channel on/off toggles (schema-ready for
 * all three; only manual WhatsApp actually sends anything in this pass —
 * see Manuscripts/Index.tsx's "Send Bill" action) plus Twilio credentials
 * for the future bulk-send path. The Twilio fields are hidden behind the
 * landlord's per-tenant `bulk_whatsapp_enabled` entitlement
 * (bill-notifications.md section 3's "UI split") — shown disabled with an
 * explanation rather than fully removed, so a tenant admin knows the
 * capability exists and who to ask for it.
 */
export default function SettingsNotifications({ settings, bulk_whatsapp_entitled }: SettingsNotificationsProps) {
    return (
        <AppLayout
            title="Notifications"
            breadcrumbs={[{ label: 'Settings', href: '/settings/company' }, { label: 'Notifications' }]}
        >
            <Head title="Settings — Notifications" />

            <SettingsTabs active="notifications" />

            <div className="mb-8 flex items-center gap-4">
                <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/25">
                    <IconBellRinging size={24} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-3xl font-semibold tracking-tight text-slate-900">Notifications</h1>
                    <p className="mt-1 text-sm text-slate-500">Choose how bill reminders reach your customers.</p>
                </div>
            </div>

            <div className="max-w-3xl">
                <Form action="/settings/notifications" method="patch">
                    {({ errors, processing, recentlySuccessful }) => (
                        <div className="space-y-6">
                            <Card className="animate-fade-up">
                                <CardHeader className="border-b border-slate-100">
                                    <h2 className="text-base font-semibold text-slate-900">Channels</h2>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        Turn a channel on to make it available. Only manual WhatsApp (the free "Send Bill"
                                        wa.me link on the Manuscripts page) actually sends anything today — Email and SMS
                                        sending are planned for a later release, this just reserves the setting.
                                    </p>
                                </CardHeader>
                                <CardBody className="space-y-4 p-6">
                                    {/*
                                        Each checkbox is paired with a hidden "0" input sharing its
                                        `name`, placed before it in DOM order. Inertia's <Form> submits
                                        the real browser FormData — an UNCHECKED checkbox contributes
                                        NOTHING to it at all (not "0", nothing), which would otherwise
                                        make unchecking a channel indistinguishable from never having
                                        submitted the field. When checked, the checkbox's own value="1"
                                        appears later in the same FormData and overwrites the hidden
                                        "0" (PHP's request parsing keeps the last value for a repeated
                                        field name); when unchecked, only the hidden "0" survives. Same
                                        trick Laravel's own Blade @checkbox / old() helpers rely on.
                                    */}
                                    <label className="flex items-start gap-3 rounded-xl border border-slate-200 p-4 hover:bg-slate-50">
                                        <input type="hidden" name="whatsapp_enabled" value="0" />
                                        <input
                                            type="checkbox"
                                            name="whatsapp_enabled"
                                            value="1"
                                            defaultChecked={settings.whatsapp_enabled}
                                            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                        />
                                        <span className="flex items-center gap-2 text-sm font-medium text-slate-900">
                                            <IconBrandWhatsapp size={16} className="text-emerald-600" stroke={1.75} />
                                            WhatsApp
                                        </span>
                                    </label>
                                    <label className="flex items-start gap-3 rounded-xl border border-slate-200 p-4 hover:bg-slate-50">
                                        <input type="hidden" name="email_enabled" value="0" />
                                        <input
                                            type="checkbox"
                                            name="email_enabled"
                                            value="1"
                                            defaultChecked={settings.email_enabled}
                                            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                        />
                                        <span className="flex items-center gap-2 text-sm font-medium text-slate-900">
                                            <IconMail size={16} className="text-blue-600" stroke={1.75} />
                                            Email
                                        </span>
                                    </label>
                                    <label className="flex items-start gap-3 rounded-xl border border-slate-200 p-4 hover:bg-slate-50">
                                        <input type="hidden" name="sms_enabled" value="0" />
                                        <input
                                            type="checkbox"
                                            name="sms_enabled"
                                            value="1"
                                            defaultChecked={settings.sms_enabled}
                                            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                        />
                                        <span className="flex items-center gap-2 text-sm font-medium text-slate-900">
                                            <IconMessage size={16} className="text-purple-600" stroke={1.75} />
                                            SMS
                                        </span>
                                    </label>
                                </CardBody>
                            </Card>

                            <Card className="animate-fade-up">
                                <CardHeader className="border-b border-slate-100">
                                    <div className="flex items-center gap-3">
                                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                            <IconBrandWhatsapp size={18} stroke={1.75} />
                                        </span>
                                        <div>
                                            <h2 className="text-base font-semibold text-slate-900">Bulk WhatsApp (Twilio)</h2>
                                            <p className="mt-0.5 text-xs text-slate-500">
                                                Your own Twilio account, billed to you directly. Not required for manual sending.
                                            </p>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardBody className="p-6">
                                    {!bulk_whatsapp_entitled ? (
                                        <div className="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                            <IconLock size={18} stroke={1.75} className="mt-0.5 shrink-0" />
                                            <p>
                                                Bulk WhatsApp isn't enabled for this workspace yet. Ask ShalomTech to enable it —
                                                once they do, you can enter your Twilio credentials here. The free manual
                                                "Send Bill" WhatsApp link on the Manuscripts page works regardless.
                                            </p>
                                        </div>
                                    ) : null}
                                    <fieldset
                                        disabled={!bulk_whatsapp_entitled}
                                        className={`grid gap-6 md:grid-cols-2 ${bulk_whatsapp_entitled ? 'mt-0' : 'mt-4 opacity-50'}`}
                                    >
                                        <div>
                                            <TextInput
                                                id="twilio_account_sid"
                                                name="twilio_account_sid"
                                                label="Twilio Account SID"
                                                defaultValue={settings.twilio_account_sid ?? ''}
                                                error={errors.twilio_account_sid}
                                                placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                                className="rounded-xl px-4 py-3"
                                            />
                                        </div>
                                        <div>
                                            <TextInput
                                                id="twilio_auth_token"
                                                name="twilio_auth_token"
                                                type="password"
                                                label="Twilio Auth Token"
                                                defaultValue={settings.twilio_auth_token ?? ''}
                                                error={errors.twilio_auth_token}
                                                placeholder="••••••••••••••••••••••••••••••••"
                                                className="rounded-xl px-4 py-3"
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <TextInput
                                                id="twilio_whatsapp_number"
                                                name="twilio_whatsapp_number"
                                                label="Twilio WhatsApp Sender Number"
                                                defaultValue={settings.twilio_whatsapp_number ?? ''}
                                                error={errors.twilio_whatsapp_number}
                                                placeholder="+14155238886"
                                                className="rounded-xl px-4 py-3"
                                            />
                                        </div>
                                    </fieldset>
                                </CardBody>
                            </Card>

                            <div className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    {recentlySuccessful && (
                                        <span className="flex items-center gap-1.5 text-sm font-medium text-emerald-600 animate-fade-up">
                                            <IconCheck size={16} />
                                            Changes saved successfully
                                        </span>
                                    )}
                                    {errors.whatsapp_enabled && (
                                        <span className="flex items-center gap-1.5 text-sm font-medium text-red-600">
                                            <IconAlertCircle size={16} />
                                            {errors.whatsapp_enabled}
                                        </span>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full rounded-xl px-6 py-2.5 text-sm font-semibold bg-slate-900 hover:bg-slate-800 text-white shadow-lg shadow-slate-900/10 sm:w-auto"
                                >
                                    {processing && <LoadingSpinner className="mr-2 text-white" />}
                                    {processing ? 'Saving…' : 'Save Changes'}
                                </Button>
                            </div>
                        </div>
                    )}
                </Form>
            </div>
        </AppLayout>
    );
}
