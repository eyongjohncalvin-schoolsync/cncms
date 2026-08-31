import { ReactNode } from 'react';
import { IconBroadcast, IconRouteAltLeft, IconReceipt2, IconChartBar } from '@tabler/icons-react';

/**
 * Public-facing shell for Welcome/Login/Register/RegisterWorkspace/Pending —
 * the only screens that render before (or between) authentication.
 *
 * Split-screen: a dark broadcast-themed brand panel on the left (lg+ only),
 * the form column on the right. The panel carries the identity so every
 * public page inherits it for free; pages only provide their own heading +
 * form as `children`. Stays on the app's slate/blue palette and the
 * Instrument Serif / Instrument Sans pairing so stepping into the admin UI
 * afterwards doesn't feel like a different product (per
 * self-service-onboarding.md's "same product" brief and
 * frontend-design-system.md). The one memorable touch: the concentric
 * broadcast-signal rings behind the wordmark — a quiet nod to what a cable
 * operator actually does, no motion required.
 */

const CAPABILITIES = [
    { icon: IconReceipt2, title: 'Billing that runs itself', body: 'Monthly manuscripts, arrears, credit, and printable bills — one calculation.' },
    { icon: IconRouteAltLeft, title: 'Every zone, every agent', body: 'Field collection, disconnections, and reconnections tracked to the customer.' },
    { icon: IconChartBar, title: 'Money you can see', body: 'Collection rate, P&L, and a full audit trail for every payment.' },
];

export function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-screen bg-slate-50">
            {/* ------------------------------------------------------------------ */}
            {/* Brand panel — lg+ only                                             */}
            {/* ------------------------------------------------------------------ */}
            <aside className="relative hidden flex-1 overflow-hidden bg-slate-950 lg:flex">
                {/* Layered background: deep blue-slate gradient + faint dot grid +
                    concentric signal rings anchored top-left. All decorative. */}
                <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                    <div className="absolute inset-0 bg-gradient-to-br from-slate-950 via-blue-950 to-slate-950" />
                    <div
                        className="absolute inset-0 opacity-30"
                        style={{
                            backgroundImage: 'radial-gradient(circle at center, #334155 1px, transparent 1px)',
                            backgroundSize: '22px 22px',
                        }}
                    />
                    {[420, 640, 880, 1140].map((size, i) => (
                        <div
                            key={size}
                            className="absolute rounded-full border border-blue-400/10"
                            style={{
                                width: size,
                                height: size,
                                top: 96 - size / 2,
                                left: 112 - size / 2,
                                borderColor: `rgba(96,165,250,${0.14 - i * 0.03})`,
                            }}
                        />
                    ))}
                    <div className="absolute -bottom-32 -left-24 h-96 w-96 rounded-full bg-blue-600/20 blur-3xl" />
                    <div className="absolute -right-24 top-1/3 h-80 w-80 rounded-full bg-indigo-500/10 blur-3xl" />
                </div>

                <div className="relative z-10 flex w-full flex-col justify-between p-12 xl:p-14">
                    <div className="animate-fade-up flex items-center gap-3">
                        <span className="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-950/40 ring-1 ring-white/10">
                            <IconBroadcast size={24} stroke={1.75} />
                        </span>
                        <div className="leading-tight">
                            <p className="font-display text-2xl text-white">CNCMS</p>
                            <p className="text-xs font-medium tracking-wide text-blue-200/60">SWECOM PLC</p>
                        </div>
                    </div>

                    <div className="max-w-md">
                        <h2
                            className="animate-fade-up font-display text-4xl leading-[1.15] text-white xl:text-5xl"
                            style={{ animationDelay: '80ms' }}
                        >
                            The cable operator&apos;s
                            <br />
                            control room.
                        </h2>
                        <p
                            className="animate-fade-up mt-4 text-[15px] leading-relaxed text-blue-100/70"
                            style={{ animationDelay: '140ms' }}
                        >
                            Subscriptions, billing, zones, and payments for a real network — in one
                            workspace.
                        </p>

                        <ul className="mt-10 flex flex-col gap-6">
                            {CAPABILITIES.map(({ icon: Icon, title, body }, i) => (
                                <li
                                    key={title}
                                    className="animate-fade-up flex gap-4"
                                    style={{ animationDelay: `${200 + i * 70}ms` }}
                                >
                                    <span className="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/5 text-blue-300 ring-1 ring-inset ring-white/10">
                                        <Icon size={18} stroke={1.75} />
                                    </span>
                                    <div>
                                        <p className="text-sm font-semibold text-white">{title}</p>
                                        <p className="mt-0.5 text-[13px] leading-relaxed text-blue-100/55">{body}</p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <p
                        className="animate-fade-up text-xs text-blue-200/40"
                        style={{ animationDelay: '440ms' }}
                    >
                        Built by ShalomTech · Kumba 3, Cameroon
                    </p>
                </div>
            </aside>

            {/* ------------------------------------------------------------------ */}
            {/* Form column                                                        */}
            {/* ------------------------------------------------------------------ */}
            <main className="relative flex w-full shrink-0 flex-col overflow-y-auto bg-gradient-to-b from-slate-50 via-white to-slate-50 px-5 py-8 sm:px-8 lg:w-[30rem] lg:px-12 xl:w-[33rem]">
                {/* Faint decorative wash, mobile + tablet where the brand panel
                    is hidden — keeps the surface from reading as flat white. */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 overflow-hidden lg:hidden"
                >
                    <div className="absolute -top-24 left-1/2 h-64 w-64 -translate-x-1/2 rounded-full bg-blue-400/10 blur-3xl" />
                </div>

                <div className="relative mx-auto flex w-full max-w-[400px] flex-1 flex-col justify-center py-4">
                    {/* Compact wordmark — only where the brand panel isn't shown. */}
                    <div className="animate-fade-up mb-8 flex items-center gap-2.5 lg:hidden">
                        <span className="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-md shadow-blue-600/20 ring-4 ring-blue-600/10">
                            <IconBroadcast size={22} stroke={1.75} />
                        </span>
                        <div className="leading-tight">
                            <p className="font-display text-xl text-slate-900">CNCMS</p>
                            <p className="text-xs font-medium text-slate-400">SWECOM PLC — Cable Network Management</p>
                        </div>
                    </div>

                    {children}
                </div>
            </main>
        </div>
    );
}
