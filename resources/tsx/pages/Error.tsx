import { Head, Link } from '@inertiajs/react';
import { IconAlertTriangle, IconClockHour4, IconHourglass, IconSearchOff, IconShieldLock, IconTools } from '@tabler/icons-react';

interface ErrorProps {
    status: number;
}

// @tabler/icons-react doesn't export its shared icon-component type, so
// derive it structurally from one of the icons actually imported above —
// every icon in the package shares the same ForwardRefExoticComponent shape.
type TablerIcon = typeof IconAlertTriangle;

const MESSAGES: Record<number, { title: string; description: string; icon: TablerIcon }> = {
    403: {
        title: 'Access denied',
        description: "You don't have permission to view this page. If you think this is a mistake, contact your administrator.",
        icon: IconShieldLock,
    },
    404: {
        title: 'Page not found',
        description: "The page you're looking for doesn't exist or may have been moved.",
        icon: IconSearchOff,
    },
    419: {
        title: 'Session expired',
        description: 'Your session expired for security reasons. Please try again.',
        icon: IconClockHour4,
    },
    429: {
        title: 'Too many requests',
        description: "You've made too many requests. Please wait a moment and try again.",
        icon: IconHourglass,
    },
    500: {
        title: 'Something went wrong',
        description: "An unexpected error occurred on our end. It's been noted — please try again shortly.",
        icon: IconAlertTriangle,
    },
    503: {
        title: 'Service unavailable',
        description: "We're performing maintenance right now. Please check back in a few minutes.",
        icon: IconTools,
    },
};

const DEFAULT_MESSAGE = {
    title: 'Unexpected error',
    description: 'Something unexpected happened. Please try again.',
    icon: IconAlertTriangle,
};

/**
 * Global fallback page for any uncaught backend error on a web/Inertia
 * request. Rendered by bootstrap/app.php's withExceptions(...)->respond()
 * closure whenever app.debug is false and the response status is one of
 * the mapped codes above — so a bug like a raw PHP exception or a
 * TypeError never surfaces as a stack trace or a blank page to an end
 * user, it always lands here instead. In local/debug mode this page is
 * bypassed entirely; Laravel's normal detailed error page renders so
 * developers keep full stack traces.
 */
export default function Error({ status }: ErrorProps) {
    const { title, description, icon: Icon } = MESSAGES[status] ?? DEFAULT_MESSAGE;

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-b from-slate-100 via-slate-50 to-slate-100 px-4">
            <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <div className="absolute -top-24 left-1/2 h-72 w-72 -translate-x-[70%] rounded-full bg-blue-400/20 blur-3xl" />
                <div className="absolute bottom-0 right-0 h-72 w-72 translate-x-1/3 translate-y-1/3 rounded-full bg-slate-300/25 blur-3xl" />
            </div>

            <div className="animate-fade-up relative flex w-full max-w-sm flex-col items-center gap-3 rounded-2xl border border-slate-200/70 bg-white px-8 py-10 text-center shadow-xl shadow-slate-900/[0.06]">
                <Head title={title} />
                <span className="mb-2 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-blue-600 text-white shadow-md shadow-blue-600/20 ring-4 ring-blue-600/10">
                    <Icon size={26} stroke={1.75} />
                </span>
                <p className="text-sm font-semibold text-blue-600">Error {status}</p>
                <h1 className="font-display text-3xl text-slate-900">{title}</h1>
                <p className="max-w-sm text-sm leading-relaxed text-slate-500">{description}</p>
                <Link
                    href="/"
                    className="mt-4 inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                >
                    Go to homepage
                </Link>
            </div>
        </div>
    );
}
