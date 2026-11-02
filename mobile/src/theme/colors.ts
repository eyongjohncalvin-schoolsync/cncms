import { Appearance } from 'react-native';
import { getAppearanceChoice } from './appearance';

/**
 * Flat, AAA-contrast (7:1 minimum for body text) color tokens. No
 * gradients, no blur — see mobile-app-react-native.md §6.
 *
 * Two palettes of the same shape (docs/plans/mobile-manager-app.md,
 * wave 6, the owner's chosen design): `light` (design A — off-white
 * ground, white cards, deep green) and `dark` (design B — near-black
 * ground, teal, amber). The phone's own light/dark setting picks one when
 * the app opens; every screen's StyleSheet is built from `colors` at that
 * moment, so a change of the phone's setting applies on the next open —
 * never mid-way through a form.
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
const light = {
    // Design A: an off-white ground so white cards stand out (2026-11, wave 6).
    background: '#F6F7F5',
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
        // Arrears Adjustment (2026-08-28) — mirrors the web app's own
        // "Adjust Arrears" modal, which uses purple-600 as, per that
        // component's own doc comment, "the one genuinely unclaimed color"
        // on Customers/Show.tsx specifically (a page-local choice, not tied
        // to the web nav's own NAV_ACCENTS 'purple' = Agents assignment).
        // On mobile, plain purple is already claimed by `expense` above
        // (Record Expense / Resources) — reusing it here would blur two
        // unrelated feature areas under one hue, against this app's own
        // "color used with restraint to mean something specific" rule (see
        // this file's 2026-08-27 rebrand comment). `violet` is a genuinely
        // distinct, still-unclaimed Tailwind hue family (sits between
        // indigo and purple) — verified white-on-fill via the same
        // relative-luminance formula the rebrand pass used, not eyeballed.
        arrears: '#5B21B6', // violet-800, ~8.98:1 white-on-fill (AAA)
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

    // Pale fills behind a feature area's notes (were hard-coded per screen).
    tint: {
        expense: '#F5EBFC',
        complaint: '#FAE8FF',
        arrears: '#F3E8FF',
    },
    // Text on the Home hero card's deep green.
    heroText: '#ECFDF5',
    // The Undo link on the inbox's dark countdown bar.
    undo: '#FCD34D',
    // The phone's status bar text over this palette.
    statusBar: 'dark' as 'dark' | 'light',
};

type Palette = typeof light;

/**
 * Design B. Accents keep each feature area's hue, lifted so they read on a
 * near-black ground; fills are bright, so `textInverse` (text on a fill)
 * is dark. Every text pair is ≥7:1 like the light palette.
 */
const dark: Palette = {
    background: '#0B1016',
    surface: '#141B24',
    surfaceMuted: '#1C2530',
    border: '#2A3644',
    textPrimary: '#E8EDF3',
    textSecondary: '#9AA7B6',
    textInverse: '#0B1016',
    accent: {
        home: '#93C5FD',
        customers: '#A5B4FC',
        payment: '#2DD4BF',
        history: '#F5B544',
        expense: '#D8B4FE',
        complaint: '#F0ABFC',
        arrears: '#C4B5FD',
    },
    status: {
        offlineBg: '#3A2A0A',
        offlineFg: '#F5B544',
        offlineDot: '#D97706',
        syncingBg: '#102A4C',
        syncingFg: '#93C5FD',
        syncingDot: '#3B82F6',
        syncedBg: '#0E2E22',
        syncedFg: '#6EE7B7',
        syncedDot: '#22C55E',
        errorBg: '#3B1416',
        errorFg: '#FCA5A5',
        errorDot: '#F87171',
        pendingBg: '#1C2530',
        pendingFg: '#CBD5E1',
        verifiedBg: '#0E2E22',
        verifiedFg: '#6EE7B7',
        rejectedBg: '#3B1416',
        rejectedFg: '#FCA5A5',
    },
    danger: '#F87171',
    dangerBg: '#3B1416',
    // WhatsApp's own bright green: dark text on it is ~10:1.
    whatsapp: '#25D366',
    tint: {
        expense: '#2A1B3D',
        complaint: '#3A1640',
        arrears: '#251B45',
    },
    heroText: '#0B1016',
    undo: '#B45309',
    statusBar: 'light',
};

const appearanceChoice = getAppearanceChoice();

// The choice also drives the phone's own pieces (alerts, keyboard, pickers);
// 'unspecified' hands them back to the phone after "Match my phone".
Appearance.setColorScheme(appearanceChoice === 'system' ? 'unspecified' : appearanceChoice);

/** Settings → Appearance when the app opened, else the phone's setting: 'dark' or 'light'. */
export const colorScheme: 'light' | 'dark' = appearanceChoice !== 'system'
    ? appearanceChoice
    : Appearance.getColorScheme() === 'dark' ? 'dark' : 'light';

export const colors: Palette = colorScheme === 'dark' ? dark : light;

export type AccentKey = keyof typeof colors.accent;
