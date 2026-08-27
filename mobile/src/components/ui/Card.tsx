import { Pressable, View, StyleSheet, type StyleProp, type ViewStyle } from 'react-native';
import { colors } from '../../theme/colors';
import { radius, spacing } from '../../theme/tokens';

interface CardProps {
    children: React.ReactNode;
    style?: StyleProp<ViewStyle>;
    onPress?: () => void;
    /** Tone-matched left/top accent stripe — conceptually mirrors the web
     * StatCard's border-t-4 tone stripe (resources/tsx/components/ui/StatCard.tsx),
     * adapted to a flat solid color since RN has no Tailwind border utilities. */
    accentColor?: string;
}

export function Card({ children, style, onPress, accentColor }: CardProps) {
    const content = (
        <View
            style={[
                styles.card,
                accentColor ? { borderTopWidth: 4, borderTopColor: accentColor } : null,
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
    pressed: {
        opacity: 0.9,
    },
});
