/**
 * Flat, AAA-contrast (7:1 minimum for body text) color tokens. No
 * gradients, no blur — see mobile-app-react-native.md §6. Dark mode is
 * deliberately deferred (§6): daytime outdoor legibility wants high
 * luminance, so there is only one palette for v1.
 *
 * `accent` mirrors the web app's NAV_ACCENTS concept (one flat color per
 * feature area) without copying its literal Tailwind gradient classes —
 * see resources/tsx/layouts/AppLayout.tsx for the web original.
 */
export const colors = {
    background: '#FFFFFF',
    surface: '#FFFFFF',
    surfaceMuted: '#F1F5F9', // slate-100
    border: '#CBD5E1', // slate-300

    textPrimary: '#0F172A', // slate-900 — ~17.9:1 on white
    textSecondary: '#334155', // slate-700 — ~9.7:1 on white (AAA-safe, not slate-500)
    textInverse: '#FFFFFF',

    // Feature-area accents (mirrors web NAV_ACCENTS, one flat hue each).
    accent: {
        home: '#1D4ED8', // blue-700
        customers: '#4338CA', // indigo-700
        payment: '#15803D', // green-700
        history: '#B45309', // amber-700
        expense: '#7E22CE', // purple-700
        // Matches the web app's NAV_ACCENTS 'fuchsia' entry for Complaint
        // Desk (complaint-desk.md section 6) — deliberately not red/amber,
        // which would make the entry look alarming even when the queue is
        // empty (the same "calm until actually urgent" rule the sync strip
        // follows).
        complaint: '#A21CAF', // fuchsia-700
    },

    // Status semantics — used by Badge/StatusPill and the sync strip.
    status: {
        // Calm, deliberately NOT red — offline/queuing is normal operation.
        offlineBg: '#FEF3C7', // amber-100
        offlineFg: '#92400E', // amber-800 — ~6.5:1, readable dark amber text
        offlineDot: '#D97706', // amber-600

        syncingBg: '#DBEAFE', // blue-100
        syncingFg: '#1E40AF', // blue-800
        syncingDot: '#2563EB',

        syncedBg: '#DCFCE7', // green-100
        syncedFg: '#166534', // green-800
        syncedDot: '#16A34A',

        // Reserved exclusively for real errors — must never look like the
        // offline/queuing amber. See mobile-app-react-native.md §5.
        errorBg: '#FEE2E2', // red-100
        errorFg: '#991B1B', // red-800
        errorDot: '#DC2626',

        pendingBg: '#F1F5F9',
        pendingFg: '#334155',
        verifiedBg: '#DCFCE7',
        verifiedFg: '#166534',
        rejectedBg: '#FEE2E2',
        rejectedFg: '#991B1B',
    },

    danger: '#B91C1C',
    dangerBg: '#FEE2E2',

    // Dedicated brand-recognition color for the "Send Bill via WhatsApp"
    // action (mobile-app-react-native.md §6: check existing conventions
    // before introducing a new color). Deliberately NOT WhatsApp's bright
    // brand green (#25D366) — white-on-#25D366 measures well under the
    // AAA 7:1 minimum this app requires for interactive text (~2.5:1).
    // #075E54 is WhatsApp's own darker teal (used in their header/app bar),
    // still immediately recognizable as "WhatsApp", and white text on it
    // measures ~7.7:1 — AAA-safe. Also deliberately distinct from
    // `accent.payment` (green-700) so this doesn't read as a payment
    // action — it isn't one.
    whatsapp: '#075E54',
} as const;

export type AccentKey = keyof typeof colors.accent;
