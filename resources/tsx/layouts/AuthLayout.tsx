import { ReactNode } from 'react';
import { IconBroadcast } from '@tabler/icons-react';

/**
 * Public-facing shell for Welcome/Login/Register/RegisterWorkspace/Pending —
 * the only pages that render before (or between) authentication. Carries a
 * touch more visual personality than AppLayout's internal admin chrome (per
 * self-service-onboarding.md's "Adminator-style" brief), while staying on
 * the same slate/blue palette and font-display/font-sans pairing used
 * throughout the rest of the app so navigating from here into the real admin
 * UI doesn't feel like a different product.
 */
export function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-b from-slate-100 via-slate-50 to-slate-100 px-4 py-12">
            {/* Soft decorative gradient blobs — the one "memorable" touch for the
                public entrance pages, kept subtle enough not to compete with the
                form itself. Purely decorative: aria-hidden, no layout impact. */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <div className="absolute -top-24 left-1/2 h-72 w-72 -translate-x-[70%] rounded-full bg-blue-400/20 blur-3xl" />
                <div className="absolute top-1/3 right-0 h-80 w-80 translate-x-1/3 rounded-full bg-indigo-300/15 blur-3xl" />
                <div className="absolute bottom-0 left-0 h-64 w-64 -translate-x-1/3 translate-y-1/3 rounded-full bg-slate-300/25 blur-3xl" />
            </div>

            <div className="relative w-full max-w-[420px]">
                <div className="mb-8 flex flex-col items-center text-center animate-fade-up">
                    <span className="mb-3 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-blue-600 text-white shadow-md shadow-blue-600/20 ring-4 ring-blue-600/10">
                        <IconBroadcast size={26} stroke={1.75} />
                    </span>
                    <h1 className="font-display text-4xl text-slate-900">CNCMS</h1>
                    <p className="mt-1.5 text-sm font-medium text-slate-400">SWECOM PLC — Cable Network Management</p>
                </div>
                <div
                    className="animate-fade-up rounded-2xl border border-slate-200/70 bg-white p-8 shadow-xl shadow-slate-900/[0.06] ring-1 ring-slate-900/5"
                    style={{ animationDelay: '60ms' }}
                >
                    {children}
                </div>
            </div>
        </div>
    );
}
