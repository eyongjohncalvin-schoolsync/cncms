import { useSyncExternalStore } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Tabs } from 'expo-router';
import { SyncStatusStrip } from '../../src/components/ui/SyncStatusStrip';
import { EmergencyBanner } from '../../src/components/ui/EmergencyBanner';
import { getNotificationsState, subscribeNotificationsState } from '../../src/notifications/notificationStore';
import { colors } from '../../src/theme/colors';
import { fontSize } from '../../src/theme/tokens';

function TabGlyph({
    letter,
    color,
    focused,
    showAlertDot = false,
}: {
    letter: string;
    color: string;
    focused: boolean;
    /** Secondary reinforcement ONLY — complaint-desk.md section 7 is
     * explicit that a bare tab-icon badge is never the primary mechanism
     * for the emergency broadcast (that's the full-screen interrupt +
     * persistent banner); this is just an extra glanceable cue. */
    showAlertDot?: boolean;
}) {
    return (
        <View style={styles.glyphWrapper}>
            <View style={[styles.glyphCircle, { borderColor: color, backgroundColor: focused ? color : 'transparent' }]}>
                <Text style={[styles.glyphText, { color: focused ? colors.textInverse : color }]}>{letter}</Text>
            </View>
            {showAlertDot ? <View style={styles.alertDot} /> : null}
        </View>
    );
}

/**
 * Bottom tab bar per mobile-app-react-native.md §4: Home, Customers,
 * Record Payment, History — Record Payment gets its own tab despite being
 * customer-scoped, since it's the single highest-frequency field action.
 *
 * The sync-status strip (§5) is mounted here, once, above <Tabs> — so it
 * stays visible across every tab AND every nested screen pushed within a
 * tab's own stack (e.g. a future customer detail screen), rather than
 * being re-mounted per-screen.
 *
 * EmergencyBanner stacks directly above it (complaint-desk.md section 7):
 * "reuses the existing sync-status-strip's screen real estate ... stacking
 * above ... while unacknowledged" — deliberately stacking rather than
 * replacing, so routine sync status stays visible too; it renders nothing
 * when there is no unacknowledged emergency, so the common case looks
 * identical to before this feature existed.
 */
export default function TabsLayout() {
    const notificationsState = useSyncExternalStore(subscribeNotificationsState, getNotificationsState);
    const hasUnacknowledgedEmergency = notificationsState.unacknowledgedEmergencies.length > 0;

    return (
        <View style={styles.flex}>
            <SafeAreaView edges={['top']} style={styles.stripSafeArea}>
                <EmergencyBanner />
                <SyncStatusStrip />
            </SafeAreaView>

            <Tabs
                screenOptions={{
                    headerShown: false,
                    tabBarActiveTintColor: colors.textPrimary,
                    tabBarInactiveTintColor: colors.textSecondary,
                    tabBarStyle: styles.tabBar,
                    tabBarLabelStyle: styles.tabLabel,
                }}
            >
                <Tabs.Screen
                    name="index"
                    options={{
                        title: 'Home',
                        tabBarIcon: ({ focused }) => (
                            <TabGlyph letter="H" color={colors.accent.home} focused={focused} showAlertDot={hasUnacknowledgedEmergency} />
                        ),
                    }}
                />
                <Tabs.Screen
                    name="customers"
                    options={{
                        title: 'Customers',
                        tabBarIcon: ({ focused }) => (
                            <TabGlyph letter="C" color={colors.accent.customers} focused={focused} />
                        ),
                    }}
                />
                <Tabs.Screen
                    name="record-payment"
                    options={{
                        title: 'Record Payment',
                        tabBarIcon: ({ focused }) => (
                            <TabGlyph letter="₣" color={colors.accent.payment} focused={focused} />
                        ),
                    }}
                />
                <Tabs.Screen
                    name="history"
                    options={{
                        title: 'History',
                        tabBarIcon: ({ focused }) => (
                            <TabGlyph letter="T" color={colors.accent.history} focused={focused} />
                        ),
                    }}
                />
            </Tabs>
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    stripSafeArea: { backgroundColor: colors.background },
    tabBar: {
        height: 64,
        paddingBottom: 8,
        paddingTop: 6,
        backgroundColor: colors.background,
        borderTopColor: colors.border,
    },
    tabLabel: {
        fontSize: fontSize.xs,
        fontWeight: '600',
    },
    glyphWrapper: {
        width: 26,
        height: 26,
    },
    glyphCircle: {
        width: 26,
        height: 26,
        borderRadius: 13,
        borderWidth: 1.5,
        alignItems: 'center',
        justifyContent: 'center',
    },
    glyphText: {
        fontSize: 13,
        fontWeight: '700',
    },
    alertDot: {
        position: 'absolute',
        top: -1,
        right: -1,
        width: 9,
        height: 9,
        borderRadius: 4.5,
        backgroundColor: colors.danger,
        borderWidth: 1.5,
        borderColor: colors.background,
    },
});
