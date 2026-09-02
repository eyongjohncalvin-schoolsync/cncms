import { Text, StyleSheet } from 'react-native';
import { Card } from './Card';
import { colors, type AccentKey } from '../../theme/colors';
import { fontSize, spacing } from '../../theme/tokens';

/**
 * Conceptual port of the web app's StatCard prop API
 * (resources/tsx/components/ui/StatCard.tsx) — label/value/hint/tone —
 * adapted for RN: no Tailwind, no gradients, tone drives a flat top-border
 * stripe and value color the same way the web version's `toneBorderClasses`
 * / `toneValueClasses` do. `delta` is omitted for v1 (no charts/trend
 * arrows call sites exist yet in the mobile app); can be added later
 * without breaking this API, same as the web version's additive design.
 */
export type StatCardTone = AccentKey | 'slate' | 'green' | 'red' | 'amber';

interface StatCardProps {
    label: string;
    value: string;
    hint?: string;
    tone?: StatCardTone;
    /** Optional — makes the whole tile tappable (e.g. Home's zone-snapshot
     * tiles jumping straight into a pre-filtered Customers list). Omitted
     * entirely, this renders exactly as before (non-interactive). */
    onPress?: () => void;
}

const toneColors: Record<StatCardTone, string> = {
    slate: colors.textSecondary,
    home: colors.accent.home,
    customers: colors.accent.customers,
    payment: colors.accent.payment,
    history: colors.accent.history,
    expense: colors.accent.expense,
    complaint: colors.accent.complaint,
    arrears: colors.accent.arrears,
    green: colors.status.syncedDot,
    red: colors.status.errorDot,
    amber: colors.status.offlineDot,
};

export function StatCard({ label, value, hint, tone = 'slate', onPress }: StatCardProps) {
    const accent = toneColors[tone];

    return (
        <Card accentColor={accent} style={styles.card} onPress={onPress}>
            <Text style={styles.label}>{label}</Text>
            <Text style={[styles.value, { color: accent }]}>{value}</Text>
            {hint ? <Text style={styles.hint}>{hint}</Text> : null}
        </Card>
    );
}

const styles = StyleSheet.create({
    card: {
        flex: 1,
        minWidth: 140,
    },
    label: {
        fontSize: fontSize.sm,
        fontWeight: '500',
        color: colors.textSecondary,
    },
    value: {
        marginTop: spacing.xs,
        // fontSize.xxl bumped 28→32 in tokens.ts's 2026-08-27 rebrand (cascades
        // here automatically); fontWeight bumped 700→800 directly here for
        // "big, legible numerals" — the repeated, direct finding from this
        // pass's MTN MoMo / mobile-money-app research (mobile-app-react-native.md
        // dated section).
        fontSize: fontSize.xxl,
        fontWeight: '800',
    },
    hint: {
        marginTop: spacing.xs,
        fontSize: fontSize.xs,
        color: colors.textSecondary,
    },
});
