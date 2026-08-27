import { forwardRef } from 'react';
import { TextInput as RNTextInput, View, Text, StyleSheet, type TextInputProps as RNTextInputProps } from 'react-native';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';

interface TextInputProps extends RNTextInputProps {
    label?: string;
    error?: string;
}

export const TextInput = forwardRef<RNTextInput, TextInputProps>(function TextInput(
    { label, error, style, ...rest },
    ref,
) {
    return (
        <View style={styles.container}>
            {label ? <Text style={styles.label}>{label}</Text> : null}
            <RNTextInput
                ref={ref}
                placeholderTextColor={colors.textSecondary}
                style={[styles.input, error ? styles.inputError : null, style]}
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
    inputError: {
        borderColor: colors.danger,
    },
    error: {
        fontSize: fontSize.xs,
        color: colors.danger,
    },
});
