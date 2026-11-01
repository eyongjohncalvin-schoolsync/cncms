import { StyleSheet, Switch, Text, View } from 'react-native';
import { colors } from '../../theme/colors';
import { fontSize, spacing } from '../../theme/tokens';

/**
 * "Bill this VIP" — shown under the level chips for a VIP only. Off (the
 * default), the monthly run charges them nothing new and they get no bill
 * slip or reminder (owner, 2026-11-01, as in the old system).
 */
export function VipBillingSwitch({ billed, onChange }: { billed: boolean; onChange: (billed: boolean) => void }) {
    return (
        <View style={styles.row}>
            <View style={styles.text}>
                <Text style={styles.label}>Bill this VIP</Text>
                <Text style={styles.hint}>
                    {billed ? 'Billed every month like any other customer.' : 'Not billed: no monthly charge, bill slip or reminder.'}
                </Text>
            </View>
            <Switch value={billed} onValueChange={onChange} />
        </View>
    );
}

const styles = StyleSheet.create({
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginTop: spacing.md },
    text: { flex: 1 },
    label: { fontSize: fontSize.md, fontWeight: '600', color: colors.textPrimary },
    hint: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
});
