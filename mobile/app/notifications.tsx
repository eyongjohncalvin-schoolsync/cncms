import { useCallback, useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { Card } from '../src/components/ui/Card';
import { Badge, type BadgeTone } from '../src/components/ui/Badge';
import { EmptyState } from '../src/components/ui/EmptyState';
import { getRecentNotifications } from '../src/db/notifications';
import { subscribeNotificationsState } from '../src/notifications/notificationStore';
import { formatRelativeTime } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, spacing } from '../src/theme/tokens';
import type { LocalNotification, NotificationSeverity } from '../src/types/db';

/**
 * "Notifications" — reached one tap from Home (in-app-notifications.md
 * section 6 / complaint-desk.md section 7's "keep it proportionate" note:
 * this app has no header bell like web, and doesn't need full dropdown
 * parity — a simple list is enough for v1). Display-only: reflects
 * whatever pull() last cached locally, with no local mark-read action (see
 * src/db/notifications.ts's class doc for why). The emergency tier gets
 * its own dedicated full-screen interrupt + persistent banner treatment
 * (app/emergency.tsx, src/components/ui/EmergencyBanner.tsx) — this screen
 * is for the routine feed only, so an emergency item shown here is purely
 * informational/historical, never the primary way an agent learns about
 * one.
 */
const SEVERITY_TONE: Record<NotificationSeverity, BadgeTone> = {
    info: 'neutral',
    warning: 'offline',
    urgent: 'error',
    emergency: 'error',
};

const SEVERITY_ACCENT: Record<NotificationSeverity, string> = {
    info: colors.border,
    warning: colors.status.offlineDot,
    urgent: colors.status.errorDot,
    emergency: colors.status.errorDot,
};

export default function NotificationsScreen() {
    const [items, setItems] = useState<LocalNotification[]>([]);
    const [loaded, setLoaded] = useState(false);

    const refresh = useCallback(() => {
        void getRecentNotifications().then((rows) => {
            setItems(rows);
            setLoaded(true);
        });
    }, []);

    useFocusEffect(
        useCallback(() => {
            refresh();
        }, [refresh]),
    );

    // Live-refresh whenever notificationStore republishes — SyncManager
    // calls refreshNotificationsState() after every successful pull (see
    // that store's own doc comment), so an agent sitting on this exact
    // screen when a scheduled sync lands sees the new item appear on its
    // own, rather than staring at a feed that looks stale until they leave
    // and come back to this tab.
    useEffect(() => subscribeNotificationsState(refresh), [refresh]);

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            {!loaded ? (
                <Text style={styles.emptyHint}>Loading…</Text>
            ) : items.length === 0 ? (
                <EmptyState
                    title="Nothing yet"
                    subtitle="Notifications relevant to you will show up here after your next sync."
                />
            ) : (
                items.map((item) => (
                    <Card key={item.uuid} accentColor={SEVERITY_ACCENT[item.severity]} style={styles.itemCard}>
                        <View style={styles.itemHeaderRow}>
                            <Text style={styles.itemTitle} numberOfLines={2}>
                                {item.title}
                            </Text>
                            {!item.read_at ? <Badge label="New" tone={SEVERITY_TONE[item.severity]} /> : null}
                        </View>
                        <Text style={styles.itemBody}>{item.body}</Text>
                        <Text style={styles.itemMeta}>{formatRelativeTime(item.created_at)}</Text>
                    </Card>
                ))
            )}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.sm, paddingBottom: spacing.xxl },
    emptyHint: { fontSize: fontSize.sm, color: colors.textSecondary },
    itemCard: { gap: spacing.xs },
    itemHeaderRow: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: spacing.sm },
    itemTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary, flexShrink: 1 },
    itemBody: { fontSize: fontSize.sm, color: colors.textSecondary },
    itemMeta: { fontSize: fontSize.xs, color: colors.textSecondary },
});
