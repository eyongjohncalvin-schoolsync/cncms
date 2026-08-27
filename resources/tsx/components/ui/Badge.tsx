import { ReactNode } from 'react';

type Tone = 'slate' | 'green' | 'yellow' | 'red' | 'blue';

// Confident "tint + ring" treatment (-100 fill, -800 text, -600/30 ring) —
// visible at a glance across a dense table of badges (status, verification,
// audit actions) rather than fading into the page like a -50/-700 pairing.
const toneClasses: Record<Tone, string> = {
    slate: 'bg-slate-200 text-slate-800 ring-1 ring-inset ring-slate-500/20',
    green: 'bg-green-100 text-green-800 ring-1 ring-inset ring-green-600/30',
    yellow: 'bg-yellow-100 text-yellow-800 ring-1 ring-inset ring-yellow-600/30',
    red: 'bg-red-100 text-red-800 ring-1 ring-inset ring-red-600/30',
    blue: 'bg-blue-100 text-blue-800 ring-1 ring-inset ring-blue-600/30',
};

export function Badge({ tone = 'slate', children }: { tone?: Tone; children: ReactNode }) {
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${toneClasses[tone]}`}>
            {children}
        </span>
    );
}
