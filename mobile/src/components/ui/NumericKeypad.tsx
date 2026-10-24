import { Platform, Pressable, StyleSheet, Text, Vibration, View } from 'react-native';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing } from '../../theme/tokens';
import { amountInWords } from '../../utils/amountInWords';
import { applyKeypadKey, formatGroupedAmount, keypadValueToNumber, KEYPAD_MAX_DIGITS, type KeypadKey } from '../../utils/keypad';

/**
 * Big on-screen amount keypad — Record Expense's first step. A custom pad
 * rather than the system numeric keyboard because it is faster and calmer
 * on cheap Android phones: no keyboard animation, no decimal/minus keys
 * that FCFA never needs, a '000' key (most field expenses are round
 * thousands), and 64dp keys that work with a thumb in the sun. The live
 * amount is grouped ("12 500 FCFA") and written out in words underneath so
 * a slipped zero is obvious before saving.
 *
 * Haptics: a short Vibration tick per key on Android. iOS ignores the
 * duration and always buzzes ~400ms, which would feel broken on a keypad,
 * so iOS gets no buzz (expo-haptics isn't installed).
 */

interface NumericKeypadProps {
    /** Digit string, '' when nothing typed. */
    value: string;
    onChange: (next: string) => void;
    maxDigits?: number;
    /** Colour of the live amount figure. */
    accentColor?: string;
}

const ROWS: KeypadKey[][] = [
    ['1', '2', '3'],
    ['4', '5', '6'],
    ['7', '8', '9'],
    ['000', '0', 'back'],
];

export function keypadTick(): void {
    if (Platform.OS === 'android') {
        Vibration.vibrate(8);
    }
}

export function NumericKeypad({ value, onChange, maxDigits = KEYPAD_MAX_DIGITS, accentColor = colors.accent.expense }: NumericKeypadProps) {
    const amount = keypadValueToNumber(value);
    const words = amountInWords(amount);

    function press(key: KeypadKey) {
        const next = applyKeypadKey(value, key, maxDigits);

        if (next !== value) {
            keypadTick();
            onChange(next);
        }
    }

    return (
        <View style={styles.container}>
            <View style={styles.display} accessibilityLiveRegion="polite" accessibilityLabel={words || 'No amount yet'}>
                <View style={styles.amountRow}>
                    <Text
                        style={[styles.amount, { color: value === '' ? colors.textSecondary : accentColor }]}
                        numberOfLines={1}
                        adjustsFontSizeToFit
                    >
                        {formatGroupedAmount(value)}
                    </Text>
                    <Text style={styles.currency}>FCFA</Text>
                </View>
                <Text style={styles.words} numberOfLines={2}>
                    {words || 'Type the amount'}
                </Text>
            </View>

            <View style={styles.grid}>
                {ROWS.map((row, rowIndex) => (
                    <View key={rowIndex} style={styles.row}>
                        {row.map((key) => {
                            const isBack = key === 'back';

                            return (
                                <Pressable
                                    key={key}
                                    accessibilityRole="button"
                                    accessibilityLabel={isBack ? 'Delete last digit. Hold to clear.' : key}
                                    onPress={() => press(key)}
                                    onLongPress={isBack ? () => press('clear') : undefined}
                                    style={({ pressed }) => [styles.key, isBack && styles.keyMuted, pressed && styles.keyPressed]}
                                >
                                    <Text style={[styles.keyText, key === '000' && styles.keyTextSmall]}>
                                        {isBack ? '⌫' : key}
                                    </Text>
                                </Pressable>
                            );
                        })}
                    </View>
                ))}
            </View>
        </View>
    );
}

const KEY_HEIGHT = 64;

const styles = StyleSheet.create({
    container: { gap: spacing.lg },
    display: { alignItems: 'center', paddingVertical: spacing.sm, minHeight: 112 },
    amountRow: { flexDirection: 'row', alignItems: 'baseline', gap: spacing.sm, maxWidth: '100%' },
    amount: { fontSize: 52, fontWeight: '800', letterSpacing: -0.5, flexShrink: 1 },
    currency: { fontSize: fontSize.lg, fontWeight: '700', color: colors.textSecondary },
    words: {
        marginTop: spacing.xs,
        fontSize: fontSize.sm,
        color: colors.textSecondary,
        textAlign: 'center',
        minHeight: 40,
    },
    grid: { gap: spacing.sm },
    row: { flexDirection: 'row', gap: spacing.sm },
    key: {
        flex: 1,
        height: KEY_HEIGHT,
        borderRadius: radius.md,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.surfaceMuted,
    },
    keyMuted: { backgroundColor: colors.border },
    keyPressed: { backgroundColor: colors.border, transform: [{ scale: 0.96 }] },
    keyText: { fontSize: 28, fontWeight: '700', color: colors.textPrimary },
    keyTextSmall: { fontSize: fontSize.xl },
});
