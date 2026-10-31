import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { fetchQuietHours, saveQuietHours, type QuietHours } from '../src/api/alerts';
import { extractErrorMessage } from '../src/api/client';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';

const CHOICES: { label: string; hours: QuietHours }[] = [
    { label: 'Off — alerts may ring any time', hours: { quiet_start: null, quiet_end: null } },
    { label: '21:00 to 06:00', hours: { quiet_start: '21:00', quiet_end: '06:00' } },
    { label: '22:00 to 06:00', hours: { quiet_start: '22:00', quiet_end: '06:00' } },
    { label: '22:00 to 07:00', hours: { quiet_start: '22:00', quiet_end: '07:00' } },
];

const same = (a: QuietHours, b: QuietHours) => a.quiet_start === b.quiet_start && a.quiet_end === b.quiet_end;

/**
 * Alerts → quiet hours (docs/plans/mobile-manager-app.md, wave 2). Saved on
 * the server, so it follows the person to any phone; needs a connection.
 */
export default function AlertSettingsScreen() {
    const [current, setCurrent] = useState<QuietHours | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        fetchQuietHours()
            .then(setCurrent)
            .catch((e: unknown) => setError(extractErrorMessage(e, 'Could not load your alert settings.')));
    }, []);

    async function choose(hours: QuietHours) {
        setSaving(true);
        setError(null);

        try {
            setCurrent(await saveQuietHours(hours));
        } catch (e) {
            setError(extractErrorMessage(e, 'Could not save. Check your connection.'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Text style={styles.heading} accessibilityRole="header">
                Quiet hours
            </Text>
            <Text style={styles.text}>
                During quiet hours, alerts still arrive but without sound. Emergencies always ring. Times are Cameroon time.
            </Text>

            {current === null && !error ? <ActivityIndicator /> : null}
            {error ? <Text style={styles.error}>{error}</Text> : null}

            {current !== null ? (
                <View style={styles.choices} accessibilityRole="radiogroup">
                    {CHOICES.map((choice) => {
                        const selected = same(choice.hours, current);

                        return (
                            <Pressable
                                key={choice.label}
                                accessibilityRole="radio"
                                accessibilityState={{ checked: selected, disabled: saving }}
                                disabled={saving}
                                onPress={() => void choose(choice.hours)}
                                style={[styles.choice, selected && styles.choiceSelected]}
                            >
                                <View style={[styles.dot, selected && styles.dotSelected]} />
                                <Text style={styles.choiceLabel}>{choice.label}</Text>
                            </Pressable>
                        );
                    })}
                    {!CHOICES.some((choice) => same(choice.hours, current)) ? (
                        <Text style={styles.text}>
                            Currently {current.quiet_start} to {current.quiet_end}.
                        </Text>
                    ) : null}
                </View>
            ) : null}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md },
    heading: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary },
    text: { fontSize: fontSize.sm, color: colors.textSecondary },
    error: { fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
    choices: { gap: spacing.sm },
    choice: { minHeight: touchTarget.floor, flexDirection: 'row', alignItems: 'center', gap: spacing.md, padding: spacing.md, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border },
    choiceSelected: { borderColor: colors.accent.payment, borderWidth: 2, backgroundColor: colors.status.verifiedBg },
    dot: { width: 18, height: 18, borderRadius: 9, borderWidth: 2, borderColor: colors.textSecondary },
    dotSelected: { borderColor: colors.accent.payment, backgroundColor: colors.accent.payment },
    choiceLabel: { flex: 1, fontSize: fontSize.md, color: colors.textPrimary },
});
