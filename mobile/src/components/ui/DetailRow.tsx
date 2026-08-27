import { Text, View, StyleSheet } from 'react-native';
import { colors } from '../../theme/colors';
import { fontSize, spacing } from '../../theme/tokens';

/**
 * DetailRow — a single label/value row inside a Card's field list (e.g.
 * "Name … Kelvin", "Zone … Kumba 3"). Extracted 2026-08-27 from two
 * near-identical local copies (`settings.tsx`'s and `agent-profile.tsx`'s
 * own `Field` components, each independently built by a different agent in
 * the same parallel build wave — mobile-app-react-native.md §11 — without
 * either seeing the other's work). Both screens' field lists were pixel-for-
 * pixel identical: same row/divider/label/value shape, same styling values.
 *
 * Usage: render a list of these inside a `<Card>`, passing `last` on the
 * final row so it doesn't get a trailing divider — mirrors both original
 * call sites' own convention exactly (`last` was the only prop besides
 * label/value either local copy had).
 */
interface DetailRowProps {
    label: string;
    value: string;
    /** Omit the bottom divider — pass on the final row in a list. */
    last?: boolean;
}

export function DetailRow({ label, value, last = false }: DetailRowProps) {
    return (
        <View style={[styles.row, !last && styles.rowDivider]}>
            <Text style={styles.label}>{label}</Text>
            <Text style={styles.value} numberOfLines={1}>
                {value}
            </Text>
        </View>
    );
}

const styles = StyleSheet.create({
    row: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: spacing.md,
        paddingBottom: spacing.sm,
    },
    rowDivider: {
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    label: { fontSize: fontSize.sm, color: colors.textSecondary },
    value: { fontSize: fontSize.md, fontWeight: '600', color: colors.textPrimary, flexShrink: 1, textAlign: 'right' },
});
