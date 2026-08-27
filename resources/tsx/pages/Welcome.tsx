import { Head, Link } from '@inertiajs/react';
import { IconCash, IconFileDescription, IconMapPin, IconUsers } from '@tabler/icons-react';
import { AuthLayout } from '@/layouts/AuthLayout';

const HIGHLIGHTS = [
    { label: 'Customers', icon: IconUsers },
    { label: 'Zones', icon: IconMapPin },
    { label: 'Payments', icon: IconCash },
    { label: 'Manuscripts', icon: IconFileDescription },
];

/**
 * Guest landing page. Intended to be wired up at `/` for unauthenticated
 * visitors (see routes/web.php — currently that route redirects straight to
 * `/login` for guests; the coordinator should switch it to
 * `Inertia::render('Welcome')` for guests once this page has landed).
 *
 * Kept deliberately restrained: this is a utility app's landing page, not a
 * marketing site — wordmark, one tagline, two calls to action, done. Reuses
 * AuthLayout directly so it automatically stays visually consistent with
 * Login/Register (gradient background, icon chip, card).
 */
export default function Welcome() {
    return (
        <AuthLayout>
            <Head title="Welcome" />
            <h2 className="animate-fade-up font-display text-center text-4xl leading-tight text-slate-900">
                Cable management, simplified
            </h2>
            <p
                className="animate-fade-up mt-3 mb-6 text-center text-sm leading-relaxed text-slate-500"
                style={{ animationDelay: '100ms' }}
            >
                Subscriptions, billing, zones, and payments for cable TV operators, all in one workspace.
            </p>
            <div
                className="animate-fade-up mb-6 flex flex-wrap items-center justify-center gap-2"
                style={{ animationDelay: '120ms' }}
            >
                {HIGHLIGHTS.map(({ label, icon: Icon }) => (
                    <span
                        key={label}
                        className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600"
                    >
                        <Icon size={14} stroke={1.75} className="text-blue-600" />
                        {label}
                    </span>
                ))}
            </div>
            <div className="animate-fade-up flex flex-col gap-3" style={{ animationDelay: '150ms' }}>
                <Link
                    href="/register"
                    className="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2.5 text-base font-semibold text-white transition-colors hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                >
                    Sign up
                </Link>
                <Link
                    href="/login"
                    className="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-white px-3.5 py-2.5 text-base font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400"
                >
                    Log in
                </Link>
            </div>
        </AuthLayout>
    );
}
