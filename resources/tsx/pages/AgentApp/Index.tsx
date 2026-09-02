import { Head } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import {
    IconDeviceMobile,
    IconDownload,
    IconBrandAndroid,
    IconBrandApple,
    IconShieldCheck,
    IconCamera,
    IconWifiOff,
    IconAlertTriangle,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';

interface AgentAppProps {
    android_url: string | null;
    ios_url: string | null;
    version: string;
    updated_on: string | null;
    android_min: string;
}

/**
 * "Get the Agent App" — CNCMS's React Native field app isn't on the Play
 * Store / App Store, so agents install a build directly. The build link is
 * env-driven (config/agent-app.php → AGENT_APP_ANDROID_URL); this page just
 * surfaces it with a QR and the install steps. Everything degrades to a
 * clear "not available yet" state while the env var is unset.
 */
export default function AgentApp({ android_url, ios_url, version, updated_on, android_min }: AgentAppProps) {
    const updatedLabel = updated_on
        ? new Date(updated_on).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
        : null;

    return (
        <AppLayout title="Agent App" breadcrumbs={[{ label: 'Agent App' }]}>
            <Head title="Agent App" />

            <div className="mx-auto max-w-3xl">
                <div className="mb-8 flex items-center gap-4">
                    <span className="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-linear-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/25">
                        <IconDeviceMobile size={24} stroke={1.75} />
                    </span>
                    <div>
                        <h1 className="font-display text-3xl font-semibold tracking-tight text-slate-900">CNCMS Field Agent</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Install the mobile app for recording payments and expenses in the field — works offline.
                        </p>
                    </div>
                </div>

                {/* Android — the primary build */}
                <Card className="mb-6">
                    <CardHeader className="flex items-center gap-2">
                        <IconBrandAndroid size={18} stroke={1.75} className="text-green-600" />
                        <span className="font-medium text-slate-900">Android</span>
                        <span className="ml-auto rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                            v{version}
                        </span>
                    </CardHeader>
                    <CardBody>
                        {android_url ? (
                            <div className="flex flex-col gap-6 sm:flex-row sm:items-center">
                                <div className="min-w-0 flex-1">
                                    <a
                                        href={android_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-lg bg-linear-to-br from-blue-600 to-blue-700 px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-blue-600/25 transition-all duration-150 hover:from-blue-500 hover:to-blue-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 active:scale-[0.98] sm:w-auto"
                                    >
                                        <IconDownload size={18} stroke={1.9} />
                                        Download for Android
                                    </a>
                                    <p className="mt-2 text-xs text-slate-400">
                                        Opens the build page. On a phone it downloads the <code className="rounded bg-slate-100 px-1 py-0.5">.apk</code> directly.
                                    </p>
                                    {updatedLabel && (
                                        <p className="mt-1 text-xs text-slate-400">Last updated {updatedLabel}</p>
                                    )}
                                </div>

                                <div className="flex shrink-0 flex-col items-center gap-1.5">
                                    <QRCodeSVG
                                        value={android_url}
                                        size={132}
                                        marginSize={2}
                                        className="rounded-lg border border-slate-200 bg-white p-1.5"
                                    />
                                    <span className="text-[11px] text-slate-400">Scan with the phone camera</span>
                                </div>
                            </div>
                        ) : (
                            <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center">
                                <p className="text-sm font-medium text-slate-700">No Android build published yet</p>
                                <p className="mx-auto mt-1 max-w-md text-xs text-slate-500">
                                    An administrator sets <code className="rounded bg-slate-200 px-1 py-0.5">AGENT_APP_ANDROID_URL</code> to
                                    the build link (an Expo <code className="rounded bg-slate-200 px-1 py-0.5">eas build</code> URL
                                    or a direct <code className="rounded bg-slate-200 px-1 py-0.5">.apk</code> link). This page then
                                    shows the download button and a QR code.
                                </p>
                            </div>
                        )}
                    </CardBody>
                </Card>

                {/* iOS — only if a build link exists */}
                {ios_url && (
                    <Card className="mb-6">
                        <CardHeader className="flex items-center gap-2">
                            <IconBrandApple size={18} stroke={1.75} className="text-slate-700" />
                            <span className="font-medium text-slate-900">iPhone / iPad</span>
                        </CardHeader>
                        <CardBody>
                            <a
                                href={ios_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:w-auto"
                            >
                                <IconDownload size={18} stroke={1.9} />
                                Open in TestFlight
                            </a>
                        </CardBody>
                    </Card>
                )}

                {/* Android install steps */}
                <Card className="mb-6">
                    <CardHeader>
                        <span className="font-medium text-slate-900">Installing on Android</span>
                    </CardHeader>
                    <CardBody>
                        <ol className="space-y-3 text-sm text-slate-600">
                            {[
                                'Tap Download for Android above, on the phone that will use the app.',
                                'When the download finishes, open the .apk file.',
                                'Android asks to allow installs from this source — tap Settings, turn it on, then go back.',
                                'If Play Protect warns about an unknown app, tap Install anyway — this build just isn’t on the Play Store.',
                                'Open CNCMS Field Agent and sign in with your normal CNCMS email and password.',
                            ].map((step, i) => (
                                <li key={i} className="flex gap-3">
                                    <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">
                                        {i + 1}
                                    </span>
                                    <span className="pt-0.5">{step}</span>
                                </li>
                            ))}
                        </ol>
                        <div className="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            <IconAlertTriangle size={15} stroke={1.9} className="mt-0.5 shrink-0" />
                            <span>
                                Updates aren&rsquo;t automatic. When a new version is announced, download it again from this
                                page — installing over the old one keeps your data.
                            </span>
                        </div>
                    </CardBody>
                </Card>

                {/* Requirements / what it does */}
                <Card>
                    <CardHeader>
                        <span className="font-medium text-slate-900">Before you install</span>
                    </CardHeader>
                    <CardBody>
                        <ul className="grid gap-3 text-sm text-slate-600 sm:grid-cols-2">
                            <li className="flex gap-2.5">
                                <IconBrandAndroid size={17} stroke={1.75} className="mt-0.5 shrink-0 text-green-600" />
                                <span>Android {android_min} or newer</span>
                            </li>
                            <li className="flex gap-2.5">
                                <IconWifiOff size={17} stroke={1.75} className="mt-0.5 shrink-0 text-slate-400" />
                                <span>Works offline — syncs when back online</span>
                            </li>
                            <li className="flex gap-2.5">
                                <IconCamera size={17} stroke={1.75} className="mt-0.5 shrink-0 text-slate-400" />
                                <span>Camera access, for attaching receipt photos</span>
                            </li>
                            <li className="flex gap-2.5">
                                <IconShieldCheck size={17} stroke={1.75} className="mt-0.5 shrink-0 text-slate-400" />
                                <span>Same login as the web — your role carries over</span>
                            </li>
                        </ul>
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}
