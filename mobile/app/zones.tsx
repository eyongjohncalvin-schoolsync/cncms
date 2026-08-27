import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect } from 'expo-router';
import { useAuth } from '../src/auth/AuthContext';
import { fetchZones, type ZoneApi } from '../src/api/zones';
import { getMyZoneUuid } from '../src/db/zones';
import { isNetworkError, extractErrorMessage } from '../src/api/client';
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { Card } from '../src/components/ui/Card';
import { EmptyState } from '../src/components/ui/EmptyState';
import { colors } from '../src/theme/colors';
import { fontSize, spacing } from '../src/theme/tokens';

type Phase = 'loading' | 'offline' | 'error' | 'ready';

/**
 * Zones — read-only zone information, reached one tap deeper (not a tab).
 * See mobile-app-react-native.md's zones-screen brief: this app's whole IA
 * already assumes one agent = one zone (§4's note), so this is deliberately
 * NOT a zone-management CRUD screen (App\Policies\ZonePolicy's create/
 * update/delete stay super/admin/manager-only, matching the office/web
 * panel) and NOT a full zone directory with per-zone drill-in either — just
 * (a) the agent's own zone shown clearly up top, and (b) — since
 * ZonePolicy::viewAny() is genuinely open to every role — a plain read-only
 * list of every other zone below it, for the rare case an agent needs to
 * look one up (e.g. a customer says they're moving to a different zone).
 *
 * "My Zone" is derived from the local customers cache (src/db/zones.ts),
 * not a server call — see that module's doc comment for why that's a
 * reliable signal for an `agent`-role device. The full zone list itself
 * (src/api/zones.ts) is always a live GET /api/v1/zones call: zones aren't
 * cached locally anywhere in this app (they barely ever change, and this is
 * a simple reference lookup, not a management surface worth its own SQLite
 * table), so the whole screen requires connectivity, same as
 * fetchCustomerDetail()'s reasoning in src/api/customers.ts.
 */
export default function ZonesScreen() {
    const { role } = useAuth();

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [zones, setZones] = useState<ZoneApi[]>([]);
    const [myZoneUuid, setMyZoneUuid] = useState<string | null>(null);
    const phaseRef = useRef<Phase>('loading');

    const load = useCallback(() => {
        if (!getSyncState().isOnline) {
            phaseRef.current = 'offline';
            setPhase('offline');
            return;
        }

        phaseRef.current = 'loading';
        setPhase('loading');
        setErrorMessage(null);

        // Local cache read (fast, offline-safe) alongside the live zones
        // call — a stale/missing myZoneUuid never blocks the directory list
        // below from rendering.
        void getMyZoneUuid().then(setMyZoneUuid);

        fetchZones()
            .then((response) => {
                setZones(response.data);
                phaseRef.current = 'ready';
                setPhase('ready');
            })
            .catch((error) => {
                if (isNetworkError(error)) {
                    phaseRef.current = 'offline';
                    setPhase('offline');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load zones."));
                    phaseRef.current = 'error';
                    setPhase('error');
                }
            });
    }, []);

    // Same "retry automatically once connectivity returns" behavior as
    // Reconnect/Disconnect (app/reconnect/[uuid].tsx, app/disconnect/[uuid].tsx).
    useFocusEffect(
        useCallback(() => {
            load();

            return subscribeSyncState(() => {
                if (phaseRef.current === 'offline' && getSyncState().isOnline) {
                    load();
                }
            });
        }, [load]),
    );

    const myZone = zones.find((zone) => zone.uuid === myZoneUuid) ?? null;
    const otherZones = zones.filter((zone) => zone.uuid !== myZoneUuid);

    if (phase === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Zones' }} />
                <ActivityIndicator size="large" color={colors.accent.home} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Zones' }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="Zone information isn't cached on this device — connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={load}
                />
            </View>
        );
    }

    if (phase === 'error') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Zones' }} />
                <EmptyState title="Couldn't load zones" subtitle={errorMessage ?? undefined} actionLabel="Try again" onAction={load} />
            </View>
        );
    }

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Stack.Screen options={{ title: 'Zones' }} />

            <View style={styles.section}>
                <Text style={styles.sectionTitle}>My zone</Text>
                {role === 'agent' && myZone ? (
                    <Card variant="filled">
                        <Text style={styles.heroEyebrow}>YOUR ZONE</Text>
                        <Text style={styles.heroName}>{myZone.name}</Text>
                        <Text style={styles.heroTown}>{myZone.town}</Text>
                    </Card>
                ) : (
                    <Card style={styles.itemCard}>
                        <Text style={styles.emptyHint}>
                            {role === 'agent'
                                ? "Your zone will show here once your customer list has synced."
                                : "Zone assignment is per-agent — this account isn't scoped to a single zone."}
                        </Text>
                    </Card>
                )}
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionTitle}>All zones</Text>
                {otherZones.length === 0 ? (
                    <Text style={styles.emptyHint}>No other zones found.</Text>
                ) : (
                    otherZones.map((zone) => (
                        <Card key={zone.uuid} style={styles.itemCard}>
                            <Text style={styles.zoneName}>{zone.name}</Text>
                            <Text style={styles.zoneTown}>{zone.town}</Text>
                        </Card>
                    ))
                )}
            </View>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.xl, paddingBottom: spacing.xxl },
    section: { gap: spacing.sm },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    heroEyebrow: { fontSize: fontSize.xs, fontWeight: '700', color: colors.textInverse, letterSpacing: 1 },
    heroName: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textInverse, marginTop: spacing.xs },
    heroTown: { fontSize: fontSize.md, color: colors.textInverse, marginTop: spacing.xs },
    itemCard: { gap: spacing.xs },
    zoneName: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    zoneTown: { fontSize: fontSize.sm, color: colors.textSecondary },
    emptyHint: { fontSize: fontSize.sm, color: colors.textSecondary },
});
