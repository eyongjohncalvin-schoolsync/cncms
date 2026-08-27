import { useSyncExternalStore } from 'react';
import { Pressable, Text, View, StyleSheet } from 'react-native';
import { useRouter } from 'expo-router';
import { getNotificationsState, subscribeNotificationsState } from '../../notifications/notificationStore';
import { describeEmergencyBanner } from '../../utils/emergencyState';
import { colors } from '../../theme/colors';
import { fontSize, spacing } from '../../theme/tokens';

/**
 * The persistent-banner half of complaint-desk.md section 7's emergency
 * treatment — the full-screen interrupt (app/emergency.tsx) is a one-time
 * event per app open; this is what keeps the situation visible for every
 * subsequent screen until it's actually acknowledged, reusing the sync-
 * status-strip's screen real estate by mounting directly above it (see
 * app/(tabs)/_layout.tsx) rather than a second, competing status area.
 *
 * Deliberately red/alarming — the one place in this app's UI that IS
 * allowed to look alarming (mobile-app-react-native.md section 5's "never
 * red for routine/expected states" rule is specifically about sync
 * status; an active, unacknowledged emergency is neither routine nor
 * expected). Renders nothing when there is nothing unacknowledged, so it
 * never adds visual weight to the common case.
 */
export function EmergencyBanner() {
    const state = useSyncExternalStore(subscribeNotificationsState, getNotificationsState);
    const router = useRouter();

    const view = describeEmergencyBanner(state.unacknowledgedEmergencies.length, state.emergenciesNeedingInterrupt.length);

    if (!view.visible) {
        return null;
    }

    return (
        <Pressable
            accessibilityRole={view.needsAction ? 'button' : undefined}
            accessibilityLabel={view.label ?? undefined}
            disabled={!view.needsAction}
            onPress={() => router.push('/emergency')}
            style={styles.banner}
        >
            <View style={styles.dot} />
            <Text style={styles.label} numberOfLines={2}>
                {view.label}
            </Text>
        </Pressable>
    );
}

const styles = StyleSheet.create({
    banner: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.sm,
        minHeight: 32,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.xs,
        backgroundColor: colors.dangerBg,
        borderBottomWidth: 1,
        borderBottomColor: colors.danger,
    },
    dot: {
        width: 8,
        height: 8,
        borderRadius: 4,
        backgroundColor: colors.danger,
    },
    label: {
        flexShrink: 1,
        fontSize: fontSize.xs,
        fontWeight: '700',
        color: colors.danger,
    },
});
