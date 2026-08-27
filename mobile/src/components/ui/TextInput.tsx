import { forwardRef, useState } from 'react';
import { TextInput as RNTextInput, View, Text, StyleSheet, type TextInputProps as RNTextInputProps } from 'react-native';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';

interface TextInputProps extends RNTextInputProps {
    label?: string;
    error?: string;
}

export const TextInput = forwardRef<RNTextInput, TextInputProps>(function TextInput(
    { label, error, style, onFocus, onBlur, ...rest },
    ref,
) {
    // Focus ring — new 2026-08-27, a common modern-fintech input pattern
    // (the field itself confirms it's "live," not just a static box) that
    // this component never had before. Local state only, no prop-shape
    // change: any caller's own onFocus/onBlur still fires (called through
    // below), so this is fully backward-compatible with every existing
    // call site.
    const [focused, setFocused] = useState(false);

    return (
        <View style={styles.container}>
            {label ? <Text style={styles.label}>{label}</Text> : null}
            <RNTextInput
                ref={ref}
                placeholderTextColor={colors.textSecondary}
                onFocus={(e) => {
                    setFocused(true);
                    onFocus?.(e);
                }}
                onBlur={(e) => {
                    setFocused(false);
                    onBlur?.(e);
                }}
                style={[styles.input, focused ? styles.inputFocused : null, error ? styles.inputError : null, style]}
                {...rest}
            />
            {error ? <Text style={styles.error}>{error}</Text> : null}
        </View>
    );
});

const styles = StyleSheet.create({
    container: {
        gap: spacing.xs,
    },
    label: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.textSecondary,
    },
    input: {
        minHeight: touchTarget.floor,
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: radius.md,
        paddingHorizontal: spacing.md,
        fontSize: fontSize.md,
        color: colors.textPrimary,
        backgroundColor: colors.surface,
    },
    // 2026-08-27: a thicker, brand-colored border while a field has focus —
    // colors.accent.payment doubles as this app's brand-primary (see
    // colors.ts), so this reuses that same color rather than introducing a
    // new one. borderWidth grows (1→2) instead of adding an outer glow/ring,
    // keeping this a solid-color change, not a blur/glow effect (§6).
    inputFocused: {
        borderWidth: 2,
        borderColor: colors.accent.payment,
    },
    inputError: {
        borderColor: colors.danger,
    },
    error: {
        fontSize: fontSize.xs,
        color: colors.danger,
    },
});
