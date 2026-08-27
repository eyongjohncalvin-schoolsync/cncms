/**
 * Spacing/sizing tokens. Touch targets per mobile-app-react-native.md §6:
 * 56dp for primary actions, 48dp floor for everything else — both
 * NON-NEGOTIABLE, unchanged by the 2026-08-27 rebrand below (a real
 * usability floor, not a style choice).
 */
export const spacing = {
    xs: 4,
    sm: 8,
    md: 12,
    lg: 16,
    xl: 24,
    xxl: 32,
} as const;

/**
 * Corner radius scale — rounded up across the board in the 2026-08-27
 * rebrand (see mobile-app-react-native.md's dated section). Research into
 * MTN MoMo and comparable mobile-money apps (M-Pesa, Airtel Money) found
 * generous, consistent corner rounding is one of the cheapest, lowest-risk
 * signals of a modern "fintech-polished" feel — it's a pure token-value
 * change with no layout risk, unlike gradients/blur (still avoided, see
 * colors.ts). `xl` is new: reserved for the "hero" filled-card treatment
 * (Card's `variant="filled"`), rounder than any card used before this pass.
 */
export const radius = {
    sm: 8,
    md: 14,
    lg: 20,
    xl: 28,
    pill: 999,
} as const;

export const touchTarget = {
    primary: 56,
    floor: 48,
} as const;

/**
 * Type scale. `xxl` and `display` were both bumped in the 2026-08-27
 * rebrand — both tokens are exclusively used for "the one big number on
 * this screen" (StatCard/sync-status/login/emergency headline for `xxl`;
 * Home's hero total and Record Payment's amount display for `display`) —
 * see mobile-app-react-native.md's dated section. Bigger, bolder numerals
 * were a direct, repeated finding across MTN MoMo and comparable
 * mobile-money app research: large plain numerals communicate value at a
 * glance faster than any chart (§6 already established this app avoids
 * charts entirely — this leans further into the alternative that's already
 * this app's stated strategy, not a new direction).
 */
export const fontSize = {
    xs: 12,
    sm: 14,
    md: 16,
    lg: 18,
    xl: 22,
    xxl: 32,
    display: 40,
} as const;

/**
 * Card elevation — added 2026-08-27. NOT the "no gradients, no
 * backdrop-blur/glassmorphism" rule from §6 being broken: a drop shadow is
 * a light/depth cue (a handful of extra composited pixels), not a
 * translucency effect — it costs nothing like a live blur does, and reads
 * as "crisp" rather than "hazy" in bright outdoor light, the opposite of
 * blur's problem. MTN MoMo and comparable mobile-money apps consistently
 * use exactly this kind of subtle lift for their summary/balance cards
 * (research cited in mobile-app-react-native.md's dated section). Two
 * levels only: `card` for every ordinary Card, `hero` for the one-per-screen
 * filled/hero treatment — deliberately not a bigger scale than that, so
 * elevation stays a hierarchy signal (this card matters more) rather than
 * decoration applied uniformly everywhere.
 */
export const shadow = {
    card: {
        shadowColor: '#0F172A',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.08,
        shadowRadius: 6,
        elevation: 2,
    },
    hero: {
        shadowColor: '#0F172A',
        shadowOffset: { width: 0, height: 8 },
        shadowOpacity: 0.2,
        shadowRadius: 16,
        elevation: 6,
    },
} as const;
