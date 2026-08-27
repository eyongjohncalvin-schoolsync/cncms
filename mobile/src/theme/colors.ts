/**
 * Flat, AAA-contrast (7:1 minimum for body text) color tokens. No
 * gradients, no blur — see mobile-app-react-native.md §6. Dark mode is
 * deliberately deferred (§6): daytime outdoor legibility wants high
 * luminance, so there is only one palette for v1.
 *
 * `accent` mirrors the web app's NAV_ACCENTS concept (one flat color per
 * feature area) without copying its literal Tailwind gradient classes —
 * see resources/tsx/layouts/AppLayout.tsx for the web original.
 *
 * --- 2026-08-27 rebrand (MTN-MoMo-quality-bar pass) ---
 * Every accent below was deepened one Tailwind step (700→800, in one case
 * to a custom in-between shade) and every hex was re-verified with a WCAG
 * contrast calculator (relative-luminance formula, not eyeballed) against
 * every background it's actually painted on in this codebase — several
 * were previously used as white-on-fill (Button `primary`, active filter
 * chips) and only cleared AA (~5–7:1), not this app's own stated AAA 7:1
 * floor. See mobile-app-react-native.md's dated section for the full
 * before/after ratio table and the MTN MoMo / mobile-money-app research
 * that drove the "richer, more confident" direction (not a random darken —
 * research on M-Pesa/Airtel Money/MTN MoMo consistently found "color used
 * with restraint to mean something specific," which is what one-hue-per-
 * feature-area already does here; this pass makes each of those hues
 * actually earn AAA rather than mostly-AA). The hue *identity* per feature
 * area (blue=home, indigo=customers, green=payment, amber=history,
 * purple=expense, fuchsia=complaint) is UNCHANGED — only the exact shade.
 *
 * Explicitly NOT done here, and why: no gradients were added anywhere,
 * despite the task brief explicitly allowing a "subtle, reasoned" gradient
 * exception. Solid, deeply-saturated fills plus the new `shadow.hero`
 * elevation (tokens.ts) already deliver the "bold, confident hero card"
 * quality bar MTN MoMo research asked for, without a gradient's extra
 * render cost or the risk of it reading as decorative rather than
 * functional in direct sunlight — see tokens.ts's `shadow` doc comment.
 */
export const colors = {
    background: '#FFFFFF',
    surface: '#FFFFFF',
    surfaceMuted: '#F1F5F9', // slate-100

    // Lightened one step from slate-300 (#CBD5E1) — now that every Card
    // carries a real drop shadow (tokens.ts `shadow.card`), the shadow does
    // the job of separating a card from the page; a heavier border on top
    // of that reads as visual clutter rather than definition. This is the
    // exact "elevation over border-only" shift modern fintech card design
    // research (2026 fintech UI trend research, see dated section) called
    // out by name. Not a text color, so AAA doesn't apply here.
    border: '#E2E8F0', // slate-200

    textPrimary: '#0F172A', // slate-900 — ~17.9:1 on white
    textSecondary: '#334155', // slate-700 — ~9.7:1 on white (AAA-safe, not slate-500)
    textInverse: '#FFFFFF',

    // Feature-area accents (mirrors web NAV_ACCENTS, one flat hue each).
    // 2026-08-27: deepened one step each, all independently re-verified
    // AAA (≥7:1) as white-on-fill — see file-header comment.
    accent: {
        home: '#1E40AF', // blue-800 (was blue-700 #1D4ED8, ~6.70:1 — AA only) — now ~8.72:1
        customers: '#3730A3', // indigo-800 (was indigo-700 #4338CA, ~7.90:1) — now ~9.93:1, more margin
        // Doubles as this app's de facto brand-primary color (Button's
        // `primary` variant, Home's new hero card) — deliberately not a
        // separate `brand.primary` token. "Record a payment" already IS
        // this app's single signature action, so one color serving both
        // roles avoids a second near-identical green agents would need to
        // keep in sync. Emerald rather than a plain/true green: research
        // (mobile-app-react-native.md dated section) found modern
        // mobile-money/neobank apps lean toward a cooler, richer green-teal
        // for their brand color over a flatter forest green — reads as more
        // "premium fintech," stays unambiguously "green = money."
        payment: '#065F46', // emerald-800 (was green-700 #15803D, ~5.02:1 — AA only, the app's own primary button!) — now ~7.68:1
        // Custom shade between Tailwind amber-800 (#92400E, ~7.09:1 — passes
        // but by a razor-thin margin, not a real safety margin for a 7:1
        // *minimum*) and amber-900 (#78350F, ~9.07:1 but visibly more brown
        // than amber). Tuned to keep real headroom without losing the "amber,
        // not brown" identity this hue needs to stay visually distinct from
        // `danger`/`status.offlineFg`'s red/amber family.
        history: '#8A3D0C', // ~7.63:1 (was amber-700 #B45309, ~5.02:1 — AA only)
        // Promotes what was, until this pass, a narrow LOCAL fix
        // (app/record-expense.tsx's `dateChipActive`, added stage 1 because
        // canonical purple-700 measured ~6.98:1, just under AAA) into the
        // canonical shared token — the same shade already validated in code
        // is now correct everywhere this hue is used, not just that one chip.
        expense: '#6B21A8', // purple-800 (was purple-700 #7E22CE, ~6.98:1) — now ~8.72:1
        // Matches the web app's NAV_ACCENTS 'fuchsia' entry for Complaint
        // Desk (complaint-desk.md section 6) — deliberately not red/amber,
        // which would make the entry look alarming even when the queue is
        // empty (the same "calm until actually urgent" rule the sync strip
        // follows).
        complaint: '#86198F', // fuchsia-800 (was fuchsia-700 #A21CAF, ~6.32:1 — AA only) — now ~8.24:1
    },

    // Status semantics — used by Badge/StatusPill and the sync strip.
    // SACRED (mobile-app-react-native.md §5): offline/queuing stays amber,
    // NEVER red, regardless of any other palette change. Only the *text*
    // foreground shades below were darkened one step each, 2026-08-27, to
    // close a real pre-existing AAA gap (verified, not eyeballed — see
    // dated section for the full ratio table); the background tints, the
    // dot colors, and — most importantly — which state gets which hue
    // family are all completely unchanged.
    status: {
        // Calm, deliberately NOT red — offline/queuing is normal operation.
        offlineBg: '#FEF3C7', // amber-100
        offlineFg: '#78350F', // amber-900 (was amber-800 #92400E, ~6.37:1 vs offlineBg — AA only) — now ~8.15:1
        offlineDot: '#D97706', // amber-600 — unchanged, not text, no AAA requirement

        syncingBg: '#DBEAFE', // blue-100
        syncingFg: '#1E40AF', // blue-800 — unchanged, already ~7.15:1 (AAA)
        syncingDot: '#2563EB', // unchanged

        syncedBg: '#DCFCE7', // green-100
        syncedFg: '#14532D', // green-900 (was green-800 #166534, ~6.49:1 vs syncedBg — AA only) — now ~8.30:1
        syncedDot: '#16A34A', // unchanged

        // Reserved exclusively for real errors — must never look like the
        // offline/queuing amber. See mobile-app-react-native.md §5.
        errorBg: '#FEE2E2', // red-100
        errorFg: '#7F1D1D', // red-900 (was red-800 #991B1B, ~6.80:1 vs errorBg — AA only) — now ~8.20:1
        errorDot: '#DC2626', // unchanged

        pendingBg: '#F1F5F9',
        pendingFg: '#334155', // unchanged, already ~9.45:1 (AAA)
        verifiedBg: '#DCFCE7',
        verifiedFg: '#14532D', // matches syncedFg — same math, same fix
        rejectedBg: '#FEE2E2',
        rejectedFg: '#7F1D1D', // matches errorFg — same math, same fix
    },

    // red-900 (was red-700 #B91C1C, ~6.47:1 on white — AA only, despite
    // being this app's color for its most safety-critical destructive
    // actions: Disconnect, error text, the Emergency banner). Also now
    // identical to `status.errorFg`/`rejectedFg` — one verified red for
    // every "this is a real problem" context in the app, not three
    // near-duplicate reds each independently eyeballed.
    danger: '#7F1D1D', // ~10.0:1 on white, ~8.2:1 on dangerBg's tint — AAA both ways
    dangerBg: '#FEE2E2',

    // Dedicated brand-recognition color for the "Send Bill via WhatsApp"
    // action (mobile-app-react-native.md §6: check existing conventions
    // before introducing a new color). Deliberately NOT WhatsApp's bright
    // brand green (#25D366) — white-on-#25D366 measures well under the
    // AAA 7:1 minimum this app requires for interactive text (~2.5:1).
    // #075E54 is WhatsApp's own darker teal (used in their header/app bar),
    // still immediately recognizable as "WhatsApp", and white text on it
    // measures ~7.7:1 — AAA-safe. Also deliberately distinct from
    // `accent.payment` (now emerald-800) so this doesn't read as a payment
    // action — it isn't one. Left untouched 2026-08-27: already AAA, and
    // not part of this app's own brand identity to begin with.
    whatsapp: '#075E54',
} as const;

export type AccentKey = keyof typeof colors.accent;
