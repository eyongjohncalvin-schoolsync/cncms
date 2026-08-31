import { ReactNode } from 'react';
import {
    IconAntenna,
    IconBroadcast,
    IconChartBar,
    IconDeviceTvOld,
    IconPlugConnected,
    IconReceipt2,
    IconRouteAltLeft,
    IconRouter,
    IconSatellite,
    IconTopologyStar3,
} from '@tabler/icons-react';

/**
 * Public-facing shell for Welcome/Login/Register/RegisterWorkspace/Pending —
 * the only screens that render before (or between) authentication.
 *
 * Full-bleed 50/50 split on lg+: an equipment-themed brand panel and the
 * form each take exactly half the viewport, so neither side can grow to
 * dominate the other on any screen size, and there is no page margin
 * around a centered card. The form sits in a comfortable ~420px column
 * centred in its half — the surrounding whitespace is deliberate, not a
 * gap. On < lg the panel becomes a slim wordmark strip above the form.
 *
 * Stays on the app's slate/blue palette and the Instrument Serif /
 * Instrument Sans pairing (self-service-onboarding.md's "same product"
 * brief). Panel decoration is an original scatter of line-art CATV/fiber
 * glyphs (Tabler, already a dependency) plus two "fiber run" lines — no
 * external images, nothing copyrighted.
 */

const CAPABILITIES = [
    { icon: IconReceipt2, text: 'Monthly billing, arrears & printable bills' },
    { icon: IconRouteAltLeft, text: 'Every zone, agent & disconnection tracked' },
    { icon: IconChartBar, text: 'Collection rate, P&L & a full audit trail' },
];

/** Decorative equipment glyphs scattered behind the panel content (lg+). */
const GLYPHS: { icon: typeof IconSatellite; size: number; top: string; left: string; opacity: number; rotate: number }[] = [
    { icon: IconSatellite, size: 150, top: '-3%', left: '62%', opacity: 0.06, rotate: -12 },
    { icon: IconDeviceTvOld, size: 104, top: '14%', left: '6%', opacity: 0.07, rotate: 8 },
    { icon: IconRouter, size: 96, top: '46%', left: '74%', opacity: 0.06, rotate: -6 },
    { icon: IconAntenna, size: 118, top: '70%', left: '10%', opacity: 0.06, rotate: 10 },
    { icon: IconPlugConnected, size: 80, top: '84%', left: '66%', opacity: 0.07, rotate: -18 },
    { icon: IconTopologyStar3, size: 132, top: '32%', left: '38%', opacity: 0.04, rotate: 4 },
];

export function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen bg-white lg:grid lg:grid-cols-2">
            {/* -------------------------------------------------------------- */}
            {/* Brand panel — half the viewport on lg+, a strip below it       */}
            {/* -------------------------------------------------------------- */}
            <aside className="relative flex flex-col overflow-hidden bg-slate-950 px-6 py-5 lg:px-12 lg:py-14 xl:px-16">
                <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                    <div className="absolute inset-0 bg-gradient-to-br from-slate-950 via-blue-950 to-slate-950" />
                    <div
                        className="absolute inset-0 opacity-25"
                        style={{
                            backgroundImage: 'radial-gradient(circle at center, #334155 1px, transparent 1px)',
                            backgroundSize: '22px 22px',
                        }}
                    />
                    <div className="absolute -left-1/4 top-1/4 h-px w-[150%] rotate-[24deg] bg-gradient-to-r from-transparent via-blue-400/30 to-transparent" />
                    <div className="absolute -left-1/4 top-2/3 h-px w-[150%] -rotate-[16deg] bg-gradient-to-r from-transparent via-cyan-300/20 to-transparent" />
                    <div className="absolute inset-0 hidden lg:block">
                        {GLYPHS.map(({ icon: Icon, size, top, left, opacity, rotate }, i) => (
                            <Icon
                                key={i}
                                size={size}
                                stroke={1}
                                className="absolute text-blue-200"
                                style={{ top, left, opacity, transform: `rotate(${rotate}deg)` }}
                            />
                        ))}
                    </div>
                    <div className="absolute -bottom-24 -left-16 h-72 w-72 rounded-full bg-blue-600/20 blur-3xl" />
                </div>

                <div className="relative z-10 mx-auto flex w-full max-w-md flex-1 flex-col">
                    <div className="animate-fade-up flex items-center gap-2.5">
                        <span className="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-950/40 ring-1 ring-white/10">
                            <IconBroadcast size={22} stroke={1.75} />
                        </span>
                        <div className="leading-tight">
                            <p className="font-display text-xl text-white">CNCMS</p>
                            <p className="text-[11px] font-medium tracking-wide text-blue-200/60">SWECOM PLC</p>
                        </div>
                    </div>

                    <div className="mt-auto hidden lg:block">
                        <h2
                            className="animate-fade-up font-display text-4xl leading-[1.15] text-white"
                            style={{ animationDelay: '80ms' }}
                        >
                            The cable operator&apos;s
                            <br />
                            control room.
                        </h2>
                        <ul className="mt-8 flex flex-col gap-4">
                            {CAPABILITIES.map(({ icon: Icon, text }, i) => (
                                <li
                                    key={text}
                                    className="animate-fade-up flex items-center gap-3 text-sm text-blue-100/70"
                                    style={{ animationDelay: `${150 + i * 60}ms` }}
                                >
                                    <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-white/5 text-blue-300 ring-1 ring-inset ring-white/10">
                                        <Icon size={15} stroke={1.75} />
                                    </span>
                                    {text}
                                </li>
                            ))}
                        </ul>
                        <p
                            className="animate-fade-up mt-10 text-[11px] text-blue-200/40"
                            style={{ animationDelay: '380ms' }}
                        >
                            Built by ShalomTech · Kumba 3, Cameroon
                        </p>
                    </div>
                </div>
            </aside>

            {/* -------------------------------------------------------------- */}
            {/* Form — half the viewport on lg+                                */}
            {/* -------------------------------------------------------------- */}
            <main className="flex flex-col overflow-y-auto bg-white px-6 py-9 sm:px-10 lg:py-12">
                <div className="mx-auto flex w-full max-w-[420px] flex-1 flex-col justify-center">
                    {children}
                </div>
            </main>
        </div>
    );
}
