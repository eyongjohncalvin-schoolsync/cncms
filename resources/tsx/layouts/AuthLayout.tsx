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
 * A single bounded card, centered on the page: a dark equipment-themed
 * brand panel on the left (lg+), the form on the right. Bounding the whole
 * unit (max-w-5xl) keeps the panel from ballooning on wide monitors and
 * shoving the form into a sliver — panel and form each stay a sane share of
 * ~1024px. On mobile the panel collapses to a slim header strip.
 *
 * Stays on the app's slate/blue palette and the Instrument Serif /
 * Instrument Sans pairing (per self-service-onboarding.md's "same product"
 * brief). Decoration is an original scatter of line-art CATV/fiber glyphs
 * (Tabler, already a dependency) plus a couple of "fiber run" lines — no
 * external images, nothing copyrighted.
 */

const CAPABILITIES = [
    { icon: IconReceipt2, text: 'Monthly billing, arrears & printable bills' },
    { icon: IconRouteAltLeft, text: 'Every zone, agent & disconnection tracked' },
    { icon: IconChartBar, text: 'Collection rate, P&L & a full audit trail' },
];

/** Decorative equipment glyphs scattered behind the panel content. */
const GLYPHS: { icon: typeof IconSatellite; size: number; top: string; left: string; opacity: number; rotate: number }[] = [
    { icon: IconSatellite, size: 132, top: '-4%', left: '58%', opacity: 0.06, rotate: -12 },
    { icon: IconDeviceTvOld, size: 96, top: '16%', left: '-8%', opacity: 0.07, rotate: 8 },
    { icon: IconRouter, size: 88, top: '44%', left: '70%', opacity: 0.06, rotate: -6 },
    { icon: IconAntenna, size: 110, top: '68%', left: '4%', opacity: 0.06, rotate: 10 },
    { icon: IconPlugConnected, size: 72, top: '84%', left: '62%', opacity: 0.07, rotate: -18 },
    { icon: IconTopologyStar3, size: 120, top: '30%', left: '30%', opacity: 0.045, rotate: 4 },
];

export function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4 sm:p-6">
            <div className="w-full max-w-5xl overflow-hidden rounded-2xl bg-white shadow-xl shadow-slate-900/[0.08] ring-1 ring-slate-900/5 lg:grid lg:min-h-[36rem] lg:grid-cols-[minmax(0,0.82fr)_minmax(0,1fr)]">
                {/* -------------------------------------------------------------- */}
                {/* Brand panel — full on lg+, a slim strip below it              */}
                {/* -------------------------------------------------------------- */}
                <aside className="relative overflow-hidden bg-slate-950 px-6 py-6 lg:px-10 lg:py-12">
                    <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                        <div className="absolute inset-0 bg-gradient-to-br from-slate-950 via-blue-950 to-slate-950" />
                        <div
                            className="absolute inset-0 opacity-25"
                            style={{
                                backgroundImage: 'radial-gradient(circle at center, #334155 1px, transparent 1px)',
                                backgroundSize: '22px 22px',
                            }}
                        />
                        {/* fiber runs */}
                        <div className="absolute -left-1/4 top-1/4 h-px w-[150%] rotate-[24deg] bg-gradient-to-r from-transparent via-blue-400/30 to-transparent" />
                        <div className="absolute -left-1/4 top-2/3 h-px w-[150%] -rotate-[16deg] bg-gradient-to-r from-transparent via-cyan-300/20 to-transparent" />
                        {/* equipment glyphs — lg+ only, they crowd the mobile strip */}
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

                    <div className="relative z-10 flex h-full flex-col">
                        <div className="animate-fade-up flex items-center gap-2.5">
                            <span className="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-950/40 ring-1 ring-white/10">
                                <IconBroadcast size={22} stroke={1.75} />
                            </span>
                            <div className="leading-tight">
                                <p className="font-display text-xl text-white">CNCMS</p>
                                <p className="text-[11px] font-medium tracking-wide text-blue-200/60">SWECOM PLC</p>
                            </div>
                        </div>

                        {/* Pitch + capability list — lg+ only */}
                        <div className="mt-auto hidden lg:block">
                            <h2
                                className="animate-fade-up font-display text-3xl leading-[1.15] text-white"
                                style={{ animationDelay: '80ms' }}
                            >
                                The cable operator&apos;s
                                <br />
                                control room.
                            </h2>
                            <ul className="mt-7 flex flex-col gap-3.5">
                                {CAPABILITIES.map(({ icon: Icon, text }, i) => (
                                    <li
                                        key={text}
                                        className="animate-fade-up flex items-center gap-3 text-[13px] text-blue-100/70"
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
                                className="animate-fade-up mt-9 text-[11px] text-blue-200/40"
                                style={{ animationDelay: '380ms' }}
                            >
                                Built by ShalomTech · Kumba 3, Cameroon
                            </p>
                        </div>
                    </div>
                </aside>

                {/* -------------------------------------------------------------- */}
                {/* Form                                                          */}
                {/* -------------------------------------------------------------- */}
                <main className="flex flex-col overflow-y-auto bg-white px-6 py-9 sm:px-10 lg:px-14 lg:py-12">
                    <div className="mx-auto flex w-full max-w-[400px] flex-1 flex-col justify-center">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
