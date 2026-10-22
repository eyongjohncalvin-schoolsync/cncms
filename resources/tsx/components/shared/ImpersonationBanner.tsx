import { router } from '@inertiajs/react';
import { IconUserShield } from '@tabler/icons-react';

/**
 * Shown on every page while a landlord is logged in as a workspace user
 * (App\Support\Impersonation). Deliberately loud and never dismissable —
 * the landlord must always know whose account they are acting in.
 */
export function ImpersonationBanner({ impersonation }: { impersonation: { impersonator: string | null; user: string } | null | undefined }) {
    if (!impersonation) {
        return null;
    }

    return (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-2 border-b border-red-900 bg-red-800 px-4 py-2 text-sm text-white md:px-6">
            <p className="flex items-center gap-2">
                <IconUserShield size={18} stroke={1.75} className="shrink-0" />
                <span>
                    You are logged in as <strong>{impersonation.user}</strong>
                    {impersonation.impersonator && <> ({impersonation.impersonator}, landlord)</>}. Everything you change is recorded under your name.
                </span>
            </p>
            <button
                type="button"
                onClick={() => router.post('/impersonation/stop')}
                className="rounded-md bg-white px-3 py-1 text-sm font-semibold text-red-800 shadow-sm hover:bg-red-50"
            >
                Stop impersonating
            </button>
        </div>
    );
}
