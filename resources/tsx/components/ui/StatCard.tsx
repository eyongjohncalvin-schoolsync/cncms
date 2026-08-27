import { memo, ReactNode } from 'react';
import { Card } from '@/components/ui/Card';

export type StatCardTone = 'slate' | 'blue' | 'green' | 'yellow' | 'red' | 'purple';

/**
 * A period-over-period comparison indicator (e.g. "vs last week"). Mirrors
 * the shape App\Services\ReportService::delta() returns, minus the
 * direction-vs-good/bad judgment — that's a presentation decision the
 * *caller* makes explicitly via `goodDirection`, never inferred from the
 * sign of `pct`, since a rising arrears figure is bad-on-up (red) while
 * rising collections is good-on-up (green).
 */
export interface StatCardDelta {
    pct: number;
    direction: 'up' | 'down' | 'flat';
    /** Which direction counts as good news for this particular metric. */
    goodDirection: 'up' | 'down';
    /** Optional trailing label, e.g. "vs last week". */
    label?: string;
}

interface StatCardProps {
    label: string;
    value: string;
    hint?: string;
    icon?: ReactNode;
    /**
     * Controls the color of the icon chip background/text. Optional and
     * additive — existing call sites that omit it keep the previous neutral
     * slate treatment, so this is backward-compatible with every current
     * StatCard usage.
     */
    tone?: StatCardTone;
    /**
     * Renders in the same slot as `hint`, taking priority over it when both
     * are given. Optional and additive — every existing StatCard call site
     * omits this and is unaffected. When the prior period has no data to
     * compare against, pass `hint="—"` instead of a fabricated delta rather
     * than omitting both — see App\Services\ReportService::delta()'s doc
     * comment for why it returns null in that case.
     */
    delta?: StatCardDelta;
}

const deltaArrow: Record<StatCardDelta['direction'], string> = {
    up: '▲',
    down: '▼',
    flat: '—',
};

// Chip backgrounds use the "-100"/"-700" pairing (matches Badge.tsx's
// strengthened tones) rather than a barely-there "-50"/"-600" tint — the
// icon chip is meant to read as a confident spot of color next to the bold
// value, not fade into the white card.
const toneChipClasses: Record<StatCardTone, string> = {
    slate: 'bg-slate-100 text-slate-600',
    blue: 'bg-blue-100 text-blue-700',
    green: 'bg-green-100 text-green-700',
    yellow: 'bg-yellow-100 text-yellow-700',
    red: 'bg-red-100 text-red-700',
    purple: 'bg-purple-100 text-purple-700',
};

// A tone-matched top border stripe so a whole row of stat cards reads as
// distinctly color-coded at a glance (like Stripe/Linear dashboard tiles),
// not as uniform white cards that only differ by their small icon chip.
const toneBorderClasses: Record<StatCardTone, string> = {
    slate: 'border-t-slate-300',
    blue: 'border-t-blue-500',
    green: 'border-t-green-500',
    yellow: 'border-t-yellow-500',
    red: 'border-t-red-500',
    purple: 'border-t-purple-500',
};

// The headline value itself picks up a tone-matched dark shade instead of
// flat slate-900 — e.g. arrears reading in red, income in green — so the
// number that matters most on the card carries the color, not just the
// icon beside it.
const toneValueClasses: Record<StatCardTone, string> = {
    slate: 'text-slate-900',
    blue: 'text-blue-800',
    green: 'text-green-800',
    yellow: 'text-yellow-800',
    red: 'text-red-800',
    purple: 'text-purple-800',
};

function StatCardComponent({ label, value, hint, icon, tone = 'slate', delta }: StatCardProps) {
    // Flat and off-target moves both render slate/neutral — only a delta
    // that moves in the metric's own "good" direction earns green, and only
    // one that moves against it earns red. Never inferred from the raw sign
    // of pct.
    const deltaColorClass =
        delta && delta.direction !== 'flat'
            ? delta.direction === delta.goodDirection
                ? 'text-green-700'
                : 'text-red-700'
            : 'text-slate-500';

    return (
        <Card className={`border-t-4 p-4 transition-shadow duration-150 hover:shadow-md hover:shadow-slate-900/5 ${toneBorderClasses[tone]}`}>
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-sm font-medium text-slate-500">{label}</p>
                    <p className={`mt-1 text-2xl font-semibold ${toneValueClasses[tone]}`}>{value}</p>
                    {delta ? (
                        <p className={`mt-1 text-xs font-medium ${deltaColorClass}`}>
                            {deltaArrow[delta.direction]} {Math.abs(delta.pct)}%{delta.label ? ` ${delta.label}` : ''}
                        </p>
                    ) : (
                        hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>
                    )}
                </div>
                {icon && <div className={`rounded-lg p-2 ${toneChipClasses[tone]}`}>{icon}</div>}
            </div>
        </Card>
    );
}

export const StatCard = memo(StatCardComponent);
