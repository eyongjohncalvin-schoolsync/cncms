import { View, Text, StyleSheet } from 'react-native';
import { Button } from './Button';
import { colors } from '../../theme/colors';
import { fontSize, spacing } from '../../theme/tokens';

interface EmptyStateProps {
    title: string;
    subtitle?: string;
    actionLabel?: string;
    onAction?: () => void;
}

export function EmptyState({ title, subtitle, actionLabel, onAction }: EmptyStateProps) {
    return (
        <View style={styles.container}>
            <Text style={styles.title}>{title}</Text>
            {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
            {actionLabel && onAction ? (
                <View style={styles.action}>
                    <Button title={actionLabel} onPress={onAction} variant="secondary" fullWidth={false} />
                </View>
            ) : null}
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        alignItems: 'center',
        justifyContent: 'center',
        paddingVertical: spacing.xxl,
        paddingHorizontal: spacing.xl,
        gap: spacing.sm,
    },
    title: {
        fontSize: fontSize.lg,
        fontWeight: '700',
        color: colors.textPrimary,
        textAlign: 'center',
    },
    subtitle: {
        fontSize: fontSize.md,
        color: colors.textSecondary,
        textAlign: 'center',
    },
    action: {
        marginTop: spacing.md,
    },
});
