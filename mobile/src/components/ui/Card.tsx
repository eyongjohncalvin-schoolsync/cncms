import { Pressable, View, StyleSheet, type StyleProp, type ViewStyle } from 'react-native';
import { colors } from '../../theme/colors';
import { radius, spacing, shadow } from '../../theme/tokens';

export type CardVariant = 'outlined' | 'filled';

interface CardProps {
    children: React.ReactNode;
    style?: StyleProp<ViewStyle>;
    onPress?: () => void;
    /** Tone-matched left/top accent stripe — conceptually mirrors the web
     * StatCard's border-t-4 tone stripe (resources/tsx/components/ui/StatCard.tsx),
     * adapted to a flat solid color since RN has no Tailwind border utilities.
     * Ignored when `variant="filled"` (a filled card is already a single
     * solid color — a top stripe of a second color on top of that reads as
     * a mistake, not an accent). */
    accentColor?: string;
    /**
     * 'outlined' (default, unchanged since before the 2026-08-27 rebrand):
     * this app's original flat white card with a hairline border — every
     * existing call site (15+ screens) keeps rendering exactly as before,
     * modulo the new default drop shadow (see tokens.ts `shadow.card`) and
     * the slightly larger `radius.lg`, both applied automatically via the
     * token change, not this prop.
     *
     * 'filled' is new: a solid-color "hero" treatment for the one figure a
     * screen most wants the eye to land on first — added specifically for
     * Home's "Today's collection" total (mobile-app-react-native.md's
     * 2026-08-27 dated section), the single most MTN-MoMo-like pattern
     * identified in that pass's research: a bold, high-contrast, elevated
     * balance/summary card at the top of the home screen. Opt-in via this
     * prop, so it cannot appear anywhere by accident — every other screen's
     * Cards are untouched by this addition.
     */
    variant?: CardVariant;
    /** Fill color when `variant="filled"`. Defaults to `colors.accent.payment`
     * (this app's de facto brand color — see colors.ts). Ignored for 'outlined'. */
    fillColor?: string;
}

export function Card({ children, style, onPress, accentColor, variant = 'outlined', fillColor }: CardProps) {
    const filled = variant === 'filled';

    const content = (
        <View
            style={[
                styles.card,
                filled ? shadow.hero : shadow.card,
                filled
                    ? [styles.filled, { backgroundColor: fillColor ?? colors.accent.payment }]
                    : accentColor
                      ? { borderTopWidth: 4, borderTopColor: accentColor }
                      : null,
                style,
            ]}
        >
            {children}
        </View>
    );

    if (!onPress) {
        return content;
    }

    return (
        <Pressable accessibilityRole="button" onPress={onPress} style={({ pressed }) => [pressed && styles.pressed]}>
            {content}
        </Pressable>
    );
}

const styles = StyleSheet.create({
    card: {
        backgroundColor: colors.surface,
        borderRadius: radius.lg,
        borderWidth: 1,
        borderColor: colors.border,
        padding: spacing.lg,
    },
    // Overrides for variant="filled" — no hairline border (a solid fill
    // doesn't need one), a rounder radius than the default card (the "hero"
    // scale — see tokens.ts's radius.xl doc comment), and roomier padding
    // (this card is meant to be the visual anchor of the screen, not one
    // tile among several).
    filled: {
        borderWidth: 0,
        borderRadius: radius.xl,
        padding: spacing.xl,
    },
    pressed: {
        opacity: 0.9,
    },
});
