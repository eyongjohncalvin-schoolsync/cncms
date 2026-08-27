import { useCallback, useEffect, useState } from 'react';
import { BackHandler, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect, useRouter } from 'expo-router';
import { Button } from '../src/components/ui/Button';
import { Card } from '../src/components/ui/Card';
import { getEmergenciesNeedingInterrupt } from '../src/db/notifications';
import { syncManager } from '../src/sync/SyncManager';
import { formatRelativeTime } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';
import type { LocalNotification } from '../src/types/db';

/**
 * The emergency broadcast interrupt — complaint-desk.md section 7's one
 * deliberate departure from this app's "never a blocking modal" rule.
 * Re-read that section before touching this screen: a sync error is
 * routine/expected (blocking there causes real alarm fatigue, which is
 * exactly why src/components/ui/SyncStatusStrip.tsx never blocks); a 48h
 * emergency complaint broadcast is rare-by-design (should almost never
 * fire if the escalation engine and its UI are working) and the owner
 * explicitly required it be unmissable — rare + high-stakes justifies an
 * interrupt, frequent + routine does not. This is the ONE screen in the
 * app that gets that treatment; nothing else should copy this pattern.
 *
 * Shown once per app open (app/_layout.tsx's RootNavigation pushes this
 * route only right after the local database is ready and only if
 * getEmergenciesNeedingInterrupt() is non-empty), full-screen, no header,
 * gestureEnabled:false (no swipe-to-dismiss — see the Stack.Screen options
 * in app/_layout.tsx), and no back button. The ONLY way off this screen is
 * pressing "Acknowledge" on every item — dismiss and acknowledge must
 * never be the same action (in-app-notifications.md section 5 /
 * complaint-desk.md section 6's UX note).
 *
 * Acknowledging is a real online action (App\Http\Controllers\Api\
 * NotificationController::acknowledge()), not a local-only dismiss — see
 * SyncManager.acknowledgeEmergency(). If offline, the action is queued
 * (ack_pending=1) and confirmed later once connectivity returns; either
 * way, the item disappears from THIS screen immediately (the agent has
 * taken the action), but the persistent red banner
 * (src/components/ui/EmergencyBanner.tsx) keeps showing a distinct
 * "confirming" state until the queued acknowledge actually round-trips —
 * this is the visual distinction between "acted on" and "confirmed."
 *
 * Android hardware back button (2026-08-27, stage 3 fix): `gestureEnabled:
 * false` on this route's Stack.Screen (app/_layout.tsx) only suppresses
 * iOS's edge-swipe-to-dismiss gesture — per @react-navigation/native-stack's
 * own docs, that option has no effect on Android. Without an explicit
 * BackHandler interception, Android's hardware/gesture-nav back button would
 * silently call goBack() and let an agent leave this screen having
 * acknowledged nothing, defeating the "the ONLY way off this screen is
 * Acknowledge" guarantee this doc comment (and complaint-desk.md section 7)
 * both rely on. The listener below consumes that event unconditionally
 * while this screen is focused, matching the no-gesture/no-header/no-back
 * treatment already applied on iOS.
 *
 * "Acknowledge all" (2026-08-27 stage 2 addendum): shown only when more
 * than one item is queued up, e.g. an agent who hasn't opened the app in a
 * few days while several complaints separately crossed 48h. Real incident-
 * response tools (PagerDuty/Opsgenie) support exactly this — a bulk
 * acknowledge alongside individual ones — because forcing N separate
 * confirm-taps for a screen that's already gated behind "rare + high-
 * stakes, so an interrupt is justified" adds friction without adding any
 * real safety: each item is still individually recorded as acknowledged
 * (bulk just drives the same per-item `acknowledgeEmergency()` call in a
 * loop), so this doesn't weaken the "every acknowledgment is a real,
 * tracked, per-complaint action" guarantee complaint-desk.md section 5
 * relies on — it only removes the repeated-tapping tax.
 */
export default function EmergencyScreen() {
    const router = useRouter();
    const [items, setItems] = useState<LocalNotification[]>([]);
    const [loaded, setLoaded] = useState(false);
    const [acknowledging, setAcknowledging] = useState<Set<string>>(new Set());
    const [queuedCopy, setQueuedCopy] = useState<string | null>(null);
    const [bulkAcknowledging, setBulkAcknowledging] = useState(false);

    const refresh = useCallback(async () => {
        const rows = await getEmergenciesNeedingInterrupt();
        setItems(rows);
        setLoaded(true);

        if (rows.length === 0) {
            router.back();
        }
    }, [router]);

    useFocusEffect(
        useCallback(() => {
            void refresh();
        }, [refresh]),
    );

    // See this file's class doc comment — Android-only, iOS already has no
    // swipe-back gesture via Stack.Screen's gestureEnabled:false.
    useEffect(() => {
        const subscription = BackHandler.addEventListener('hardwareBackPress', () => true);

        return () => subscription.remove();
    }, []);

    async function handleAcknowledge(uuid: string) {
        setAcknowledging((prev) => new Set(prev).add(uuid));

        const outcome = await syncManager.acknowledgeEmergency(uuid);

        if (outcome === 'queued') {
            setQueuedCopy("You're offline — this will be confirmed once you're back online.");
        }

        setAcknowledging((prev) => {
            const next = new Set(prev);
            next.delete(uuid);
            return next;
        });

        await refresh();
    }

    async function handleAcknowledgeAll() {
        setBulkAcknowledging(true);

        // Sequential, not Promise.all — each acknowledge writes to SQLite
        // and republishes notificationStore; one at a time keeps those
        // writes ordered and avoids racing refresh() calls against each
        // other. Iterates the snapshot of items captured when the button
        // was pressed, since `items` itself shrinks as each one confirms.
        for (const item of items) {
            await handleAcknowledge(item.uuid);
        }

        setBulkAcknowledging(false);
    }

    if (!loaded) {
        return <View style={styles.flex} />;
    }

    return (
        <View style={styles.flex}>
            <View style={styles.header}>
                <Text style={styles.headerEyebrow}>EMERGENCY BROADCAST</Text>
                <Text style={styles.headerTitle}>
                    {items.length === 1 ? '1 complaint needs your attention' : `${items.length} complaints need your attention`}
                </Text>
                <Text style={styles.headerHint}>
                    These have been open 48 hours or more with nobody acting on them. Acknowledging lets the team know
                    you're aware — it doesn't resolve the complaint.
                </Text>
                {items.length > 1 ? (
                    <Button
                        title={bulkAcknowledging ? 'Acknowledging all…' : `Acknowledge all ${items.length}`}
                        variant="secondary"
                        loading={bulkAcknowledging}
                        onPress={() => void handleAcknowledgeAll()}
                        style={styles.acknowledgeAllButton}
                    />
                ) : null}
            </View>

            {queuedCopy ? (
                <View style={styles.queuedBanner}>
                    <Text style={styles.queuedBannerText}>{queuedCopy}</Text>
                </View>
            ) : null}

            <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
                {items.map((item) => (
                    <Card key={item.uuid} accentColor={colors.danger} style={styles.itemCard}>
                        <Text style={styles.itemTitle}>{item.title}</Text>
                        <Text style={styles.itemBody}>{item.body}</Text>
                        <Text style={styles.itemMeta}>{formatRelativeTime(item.created_at)}</Text>
                        <Button
                            title="Acknowledge"
                            variant="danger"
                            size="large"
                            loading={acknowledging.has(item.uuid)}
                            onPress={() => void handleAcknowledge(item.uuid)}
                            style={styles.acknowledgeButton}
                        />
                    </Card>
                ))}
            </ScrollView>
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    header: {
        backgroundColor: colors.dangerBg,
        padding: spacing.lg,
        gap: spacing.xs,
        borderBottomWidth: 1,
        borderBottomColor: colors.danger,
    },
    headerEyebrow: { fontSize: fontSize.xs, fontWeight: '800', color: colors.danger, letterSpacing: 1 },
    headerTitle: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textPrimary, marginTop: spacing.xs },
    headerHint: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
    acknowledgeAllButton: { marginTop: spacing.md },
    queuedBanner: {
        backgroundColor: colors.status.offlineBg,
        paddingHorizontal: spacing.lg,
        paddingVertical: spacing.sm,
    },
    queuedBannerText: { fontSize: fontSize.sm, fontWeight: '600', color: colors.status.offlineFg },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    itemCard: { gap: spacing.xs },
    itemTitle: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    itemBody: { fontSize: fontSize.md, color: colors.textPrimary },
    itemMeta: { fontSize: fontSize.xs, color: colors.textSecondary },
    acknowledgeButton: { marginTop: spacing.sm, minHeight: touchTarget.primary, borderRadius: radius.md },
});
