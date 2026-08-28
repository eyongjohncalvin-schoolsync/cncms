import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import { View, Text, ScrollView, StyleSheet } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { Card } from '../src/components/ui/Card';
import { Badge } from '../src/components/ui/Badge';
import { Button } from '../src/components/ui/Button';
import { getQueuedPayments } from '../src/db/payments';
import { getQueuedExpenditures } from '../src/db/expenditures';
import { getAllCustomers } from '../src/db/customers';
import { getExpenseCategories } from '../src/db/categories';
import { getLastSyncAt } from '../src/db/syncMeta';
import { syncManager } from '../src/sync/SyncManager';
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { formatFcfa, formatRelativeTime } from '../src/utils/format';
import { humanizeSyncError } from '../src/utils/syncErrors';
import { colors } from '../src/theme/colors';
import { fontSize, spacing } from '../src/theme/tokens';

interface PendingItem {
    key: string;
    kind: 'payment' | 'expenditure';
    label: string;
    amount: number;
    queuedAt: string;
    failed: boolean;
    error: string | null;
}

/**
 * "Sync Status" detail sheet — a modal reached by tapping the persistent
 * SyncStatusStrip, not a tab, per mobile-app-react-native.md §4's explicit
 * "Sync Status is not a tab" decision. Shows last-successful-sync time, a
 * manual "Sync Now" trigger (calls the existing syncManager.syncNow(), no
 * sync logic reimplemented here), the pending outbox, and a distinct "Needs
 * attention" section for anything sync_status='failed' with its error
 * rephrased in plain language (see src/utils/syncErrors.ts).
 */
export default function SyncStatusScreen() {
    const liveState = useSyncExternalStore(subscribeSyncState, getSyncState);
    const [persistedLastSyncAt, setPersistedLastSyncAt] = useState<string | null>(null);
    const [items, setItems] = useState<PendingItem[]>([]);
    const [loaded, setLoaded] = useState(false);
    const [forceRefreshing, setForceRefreshing] = useState(false);

    const refresh = useCallback(async () => {
        const [queuedPayments, queuedExpenditures, customers, categories, storedLastSyncAt] = await Promise.all([
            getQueuedPayments(),
            getQueuedExpenditures(),
            getAllCustomers(),
            getExpenseCategories(),
            getLastSyncAt(),
        ]);

        setPersistedLastSyncAt(storedLastSyncAt);

        const customerNames = new Map(customers.map((c) => [c.uuid, c.name]));
        const categoryNames = new Map(categories.map((c) => [c.uuid, c.name]));

        const paymentItems: PendingItem[] = queuedPayments.map((payment) => ({
            key: `payment-${payment.local_uuid}`,
            kind: 'payment',
            label: customerNames.get(payment.customer_uuid) ?? 'Unknown customer',
            amount: payment.amount,
            queuedAt: payment.created_at,
            failed: payment.sync_status === 'failed',
            error: payment.sync_error,
        }));

        const expenditureItems: PendingItem[] = queuedExpenditures.map((expenditure) => ({
            key: `expenditure-${expenditure.local_uuid}`,
            kind: 'expenditure',
            label: categoryNames.get(expenditure.category_uuid) ?? 'Uncategorised',
            amount: expenditure.amount,
            queuedAt: expenditure.created_at,
            failed: expenditure.sync_status === 'failed',
            error: expenditure.sync_error,
        }));

        const combined = [...paymentItems, ...expenditureItems].sort((a, b) => (a.queuedAt < b.queuedAt ? 1 : -1));

        setItems(combined);
        setLoaded(true);
    }, []);

    useFocusEffect(
        useCallback(() => {
            void refresh();
        }, [refresh]),
    );

    // Re-pull the pending/failed lists whenever sync state changes (a push
    // just completed, a pull just landed) so this sheet stays live while
    // the agent is looking at it, not just on first open.
    useEffect(() => subscribeSyncState(() => void refresh()), [refresh]);

    // Distinct from the ordinary "Sync now" button/queuingProgress-driven
    // isSyncing below: a force-full-refresh pull is invisible to
    // syncingProgress (that's only ever set inside push() for queued
    // outbox items — see SyncManager.push()), so a dedicated local loading
    // flag is the only way this button can show its own busy state.
    const handleForceRefresh = useCallback(async () => {
        setForceRefreshing(true);

        try {
            await syncManager.forceFullResync();
        } finally {
            setForceRefreshing(false);
        }
    }, []);

    const lastSyncAt = liveState.lastSyncAt ?? persistedLastSyncAt;
    const failedItems = items.filter((item) => item.failed);
    const pendingItems = items.filter((item) => !item.failed);
    const isSyncing = liveState.phase === 'syncing';

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Card accentColor={colors.accent.home}>
                <Text style={styles.lastSyncLabel}>Last synced</Text>
                <Text style={styles.lastSyncValue}>{formatRelativeTime(lastSyncAt)}</Text>
                <Text style={styles.lastSyncHint}>
                    {liveState.queuedCount === 0
                        ? 'Everything on this device is synced.'
                        : `${liveState.queuedCount} item${liveState.queuedCount === 1 ? '' : 's'} waiting to sync.`}
                </Text>
                <Button
                    title={isSyncing ? 'Syncing…' : 'Sync now'}
                    onPress={() => void syncManager.syncNow('manual')}
                    loading={isSyncing}
                    style={styles.syncButton}
                />
                <Button
                    title={forceRefreshing ? 'Refreshing…' : 'Force full refresh'}
                    onPress={() => void handleForceRefresh()}
                    loading={forceRefreshing}
                    variant="secondary"
                    style={styles.forceRefreshButton}
                />
                <Text style={styles.forceRefreshHint}>
                    Re-downloads every customer's bill and arrears figures from the server, even ones
                    "Sync now" would skip. Use this if a customer's balance looks out of date.
                </Text>
            </Card>

            {failedItems.length > 0 ? (
                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Needs attention</Text>
                    {failedItems.map((item) => (
                        <Card key={item.key} accentColor={colors.status.errorDot} style={styles.itemCard}>
                            <View style={styles.itemHeaderRow}>
                                <Text style={styles.itemTitle}>
                                    {item.kind === 'payment' ? 'Payment' : 'Expense'} · {item.label}
                                </Text>
                                <Badge label="Failed" tone="error" />
                            </View>
                            <Text style={styles.itemAmount}>{formatFcfa(item.amount)}</Text>
                            <Text style={styles.itemError}>{humanizeSyncError(item.error)}</Text>
                        </Card>
                    ))}
                </View>
            ) : null}

            <View style={styles.section}>
                <Text style={styles.sectionTitle}>Pending</Text>
                {!loaded ? (
                    <Text style={styles.emptyHint}>Loading…</Text>
                ) : pendingItems.length === 0 ? (
                    <Text style={styles.emptyHint}>Nothing waiting to sync.</Text>
                ) : (
                    pendingItems.map((item) => (
                        <Card key={item.key} style={styles.itemCard}>
                            <View style={styles.itemHeaderRow}>
                                <Text style={styles.itemTitle}>
                                    {item.kind === 'payment' ? 'Payment' : 'Expense'} · {item.label}
                                </Text>
                                <Text style={styles.itemQueuedAt}>{formatRelativeTime(item.queuedAt)}</Text>
                            </View>
                            <Text style={styles.itemAmount}>{formatFcfa(item.amount)}</Text>
                        </Card>
                    ))
                )}
            </View>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    lastSyncLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary },
    lastSyncValue: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.textPrimary, marginTop: spacing.xs },
    lastSyncHint: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
    syncButton: { marginTop: spacing.lg },
    forceRefreshButton: { marginTop: spacing.sm },
    forceRefreshHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.xs },
    section: { gap: spacing.sm },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    emptyHint: { fontSize: fontSize.sm, color: colors.textSecondary },
    itemCard: { gap: spacing.xs },
    itemHeaderRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    itemTitle: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary, flexShrink: 1 },
    itemQueuedAt: { fontSize: fontSize.xs, color: colors.textSecondary },
    itemAmount: { fontSize: fontSize.lg, fontWeight: '700', color: colors.textPrimary },
    itemError: { fontSize: fontSize.sm, color: colors.status.errorFg },
});
