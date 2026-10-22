import { useEffect, useState, useSyncExternalStore } from 'react';
import { Text, View, StyleSheet } from 'react-native';
import { getMaintenanceState, subscribeMaintenanceState } from '../../maintenance/maintenanceStore';
import { describeMaintenanceBanner } from '../../utils/maintenanceState';
import { colors } from '../../theme/colors';
import { fontSize, spacing } from '../../theme/tokens';

/**
 * Platform maintenance notice (src/utils/maintenanceState.ts), stacked with
 * EmergencyBanner above the sync strip in app/(tabs)/_layout.tsx. Amber, not
 * red, in both phases: like being offline, maintenance is expected — work
 * keeps queuing on the phone and syncs once the window ends. Re-evaluated
 * every 30s so the countdown moves and the banner leaves on time.
 */
export function MaintenanceBanner() {
    const window = useSyncExternalStore(subscribeMaintenanceState, getMaintenanceState);
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!window) {
            return;
        }

        const timer = setInterval(() => setNow(Date.now()), 30000);

        return () => clearInterval(timer);
    }, [window]);

    const view = describeMaintenanceBanner(window, now);

    if (!view.visible) {
        return null;
    }

    return (
        <View accessibilityRole="alert" style={styles.banner}>
            <View style={styles.dot} />
            <Text style={styles.label} numberOfLines={3}>
                {view.label}
            </Text>
        </View>
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
        backgroundColor: colors.status.offlineBg,
        borderBottomWidth: 1,
        borderBottomColor: colors.status.offlineDot,
    },
    dot: {
        width: 8,
        height: 8,
        borderRadius: 4,
        backgroundColor: colors.status.offlineDot,
    },
    label: {
        flexShrink: 1,
        fontSize: fontSize.xs,
        fontWeight: '700',
        color: colors.status.offlineFg,
    },
});
