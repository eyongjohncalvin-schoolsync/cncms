import { View, Text, StyleSheet } from 'react-native';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing } from '../../theme/tokens';

/**
 * StatusPill — for verification-status (pending/verified/rejected) and
 * connection-status badges. Each tone pairs a distinct background+text
 * color, never relying on color alone (the label text always says the
 * state in words too) per AAA-contrast/accessibility rules in
 * mobile-app-react-native.md §6.
 */
export type BadgeTone = 'pending' | 'verified' | 'rejected' | 'offline' | 'syncing' | 'synced' | 'error' | 'neutral';

interface BadgeProps {
    label: string;
    tone?: BadgeTone;
}

const toneStyles: Record<BadgeTone, { bg: string; fg: string }> = {
    pending: { bg: colors.status.pendingBg, fg: colors.status.pendingFg },
    verified: { bg: colors.status.verifiedBg, fg: colors.status.verifiedFg },
    rejected: { bg: colors.status.rejectedBg, fg: colors.status.rejectedFg },
    offline: { bg: colors.status.offlineBg, fg: colors.status.offlineFg },
    syncing: { bg: colors.status.syncingBg, fg: colors.status.syncingFg },
    synced: { bg: colors.status.syncedBg, fg: colors.status.syncedFg },
    error: { bg: colors.status.errorBg, fg: colors.status.errorFg },
    neutral: { bg: colors.surfaceMuted, fg: colors.textSecondary },
};

export function Badge({ label, tone = 'neutral' }: BadgeProps) {
    const t = toneStyles[tone];

    return (
        <View style={[styles.pill, { backgroundColor: t.bg }]}>
            <Text style={[styles.label, { color: t.fg }]}>{label}</Text>
        </View>
    );
}

const styles = StyleSheet.create({
    pill: {
        alignSelf: 'flex-start',
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.xs,
        borderRadius: radius.pill,
    },
    label: {
        fontSize: fontSize.xs,
        fontWeight: '700',
    },
});
