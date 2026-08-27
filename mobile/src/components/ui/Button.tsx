import { ActivityIndicator, Pressable, StyleSheet, Text, type StyleProp, type ViewStyle } from 'react-native';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'dangerOutline';
/** 'large' hits the 56dp primary-action touch target; 'default' uses the
 * 48dp floor. See mobile-app-react-native.md §6. */
export type ButtonSize = 'large' | 'default';

interface ButtonProps {
    title: string;
    onPress: () => void;
    variant?: ButtonVariant;
    size?: ButtonSize;
    disabled?: boolean;
    loading?: boolean;
    style?: StyleProp<ViewStyle>;
    fullWidth?: boolean;
}

export function Button({
    title,
    onPress,
    variant = 'primary',
    size = 'default',
    disabled = false,
    loading = false,
    style,
    fullWidth = true,
}: ButtonProps) {
    const isDisabled = disabled || loading;
    const height = size === 'large' ? touchTarget.primary : touchTarget.floor;

    return (
        <Pressable
            accessibilityRole="button"
            accessibilityState={{ disabled: isDisabled }}
            onPress={onPress}
            disabled={isDisabled}
            style={({ pressed }) => [
                styles.base,
                variantStyles[variant],
                { height, opacity: isDisabled ? 0.5 : pressed ? 0.85 : 1 },
                fullWidth && styles.fullWidth,
                style,
            ]}
        >
            {loading ? (
                <ActivityIndicator color={variant === 'secondary' || variant === 'ghost' ? colors.textPrimary : colors.textInverse} />
            ) : (
                <Text style={[styles.label, textVariantStyles[variant]]} numberOfLines={1}>
                    {title}
                </Text>
            )}
        </Pressable>
    );
}

const styles = StyleSheet.create({
    base: {
        borderRadius: radius.md,
        alignItems: 'center',
        justifyContent: 'center',
        paddingHorizontal: spacing.lg,
    },
    fullWidth: {
        alignSelf: 'stretch',
    },
    label: {
        fontSize: fontSize.md,
        fontWeight: '600',
    },
});

const variantStyles: Record<ButtonVariant, ViewStyle> = {
    primary: { backgroundColor: colors.accent.payment },
    secondary: { backgroundColor: colors.surfaceMuted, borderWidth: 1, borderColor: colors.border },
    ghost: { backgroundColor: 'transparent' },
    danger: { backgroundColor: colors.danger },
    // Lower-emphasis destructive action — same danger color, but as an
    // outline rather than a solid fill, so it doesn't compete visually
    // with a screen's primary (solid) CTA. Added for Customer Detail's
    // Disconnect button (2026-08 field-ops widening put a real destructive
    // action one tap below Record Payment for the first time) — field-
    // service UX research is consistent that destructive actions should
    // read as lower-priority than the primary action, not equal weight to
    // it, even though the actual navigation target still confirms before
    // anything irreversible happens. See mobile-app-react-native.md's
    // 2026-08-27 addendum.
    dangerOutline: { backgroundColor: 'transparent', borderWidth: 1.5, borderColor: colors.danger },
};

const textVariantStyles: Record<ButtonVariant, { color: string }> = {
    primary: { color: colors.textInverse },
    secondary: { color: colors.textPrimary },
    ghost: { color: colors.textPrimary },
    danger: { color: colors.textInverse },
    dangerOutline: { color: colors.danger },
};
