import { StyleSheet, Text, View } from 'react-native';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing } from '../../theme/tokens';
import type { ExpenditureFlagApi } from '../../types/api';

/**
 * The server's control flags on an expense ("Round amount", "Dated in the
 * past"…) as small amber chips — amber, never red: a flag is something for
 * a person to look at, not an error.
 */
export function FlagChips({ flags, limit }: { flags: ExpenditureFlagApi[] | null | undefined; limit?: number }) {
    if (!flags || flags.length === 0) {
        return null;
    }

    const shown = limit ? flags.slice(0, limit) : flags;
    const hidden = flags.length - shown.length;

    return (
        <View style={styles.row}>
            {shown.map((flag) => (
                <View key={flag.key} style={styles.chip}>
                    <Text style={styles.text}>{flag.label}</Text>
                </View>
            ))}
            {hidden > 0 ? (
                <View style={styles.chip}>
                    <Text style={styles.text}>+{hidden} more</Text>
                </View>
            ) : null}
        </View>
    );
}

const styles = StyleSheet.create({
    row: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs },
    chip: {
        paddingHorizontal: spacing.sm,
        paddingVertical: 3,
        borderRadius: radius.pill,
        backgroundColor: colors.status.offlineBg,
    },
    text: { fontSize: fontSize.xs, fontWeight: '700', color: colors.status.offlineFg },
});
