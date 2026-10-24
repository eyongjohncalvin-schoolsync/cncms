import { useCallback, useEffect, useMemo, useState, useSyncExternalStore } from 'react';
import { ActivityIndicator, Alert, Platform, ScrollView, StyleSheet, Text, Vibration, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Card } from '../../src/components/ui/Card';
import { Badge, type BadgeTone } from '../../src/components/ui/Badge';
import { Button } from '../../src/components/ui/Button';
import { DetailRow } from '../../src/components/ui/DetailRow';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { FlagChips } from '../../src/components/expenses/FlagChips';
import { ReceiptImage } from '../../src/components/expenses/ReceiptImage';
import { captureReceipt } from '../../src/components/expenses/captureReceipt';
import { attachExpenditureReceipt, fetchExpenditure } from '../../src/api/expenditures';
import { extractErrorMessage, isNetworkError } from '../../src/api/client';
import { getExpenseCategories } from '../../src/db/categories';
import { getExpenditureByLocalUuid, setExpenditureReceipt } from '../../src/db/expenditures';
import { getSuppliers } from '../../src/db/suppliers';
import {
    getCachedExpenseStatuses,
    getCachedServices,
    mergeCachedExpenseStatuses,
    type CachedService,
} from '../../src/db/expenseReference';
import { syncManager } from '../../src/sync/SyncManager';
import { getSyncState, subscribeSyncState } from '../../src/sync/syncStore';
import { expenseStatusDisplay, paymentMethodLabel } from '../../src/utils/expenseControls';
import { formatFcfa, formatShortDate } from '../../src/utils/format';
import { glyphForCategoryIcon } from '../../src/utils/categoryIcons';
import { colors } from '../../src/theme/colors';
import { fontSize, radius, spacing } from '../../src/theme/tokens';
import type { ExpenditureApi } from '../../src/types/api';
import type { LocalExpenditure, LocalExpenseCategory, LocalSupplier, ReceiptSource, SyncStatus } from '../../src/types/db';

const SYNC_BADGE: Record<SyncStatus, { label: string; tone: BadgeTone }> = {
    queued: { label: 'Saved · will sync', tone: 'offline' },
    syncing: { label: 'Syncing…', tone: 'syncing' },
    synced: { label: 'Synced', tone: 'synced' },
    failed: { label: 'Sync failed', tone: 'error' },
};

/**
 * Expense detail — everything about one of this device's own expenses:
 * the server status and flags, who approved/rejected/voided it and why,
 * the receipt photo, and how/where it was paid. Opened from Resources.
 *
 * "Add receipt" appears when the server is holding the expense as
 * 'pending_evidence' (or the expense has no photo yet and hasn't synced).
 * Online with a server uuid, the photo goes straight to
 * POST /resources/expenditures/{uuid}/receipt; otherwise it is queued on
 * the local row and SyncManager uploads it via /sync/upload-receipt.
 * Renders from the local row + cached server status first, so it works
 * offline; refreshes from the server when online.
 */
export default function ExpenseDetailScreen() {
    const { localUuid } = useLocalSearchParams<{ localUuid: string }>();
    const syncState = useSyncExternalStore(subscribeSyncState, getSyncState);

    const [row, setRow] = useState<LocalExpenditure | null>(null);
    const [loaded, setLoaded] = useState(false);
    const [server, setServer] = useState<ExpenditureApi | null>(null);
    const [categories, setCategories] = useState<LocalExpenseCategory[]>([]);
    const [suppliers, setSuppliers] = useState<LocalSupplier[]>([]);
    const [services, setServices] = useState<CachedService[]>([]);
    const [attaching, setAttaching] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const loadLocal = useCallback(async (): Promise<LocalExpenditure | null> => {
        if (!localUuid) {
            return null;
        }

        const [local, cats, supplierRows, serviceRows, statuses] = await Promise.all([
            getExpenditureByLocalUuid(localUuid),
            getExpenseCategories(),
            getSuppliers(),
            getCachedServices(),
            getCachedExpenseStatuses(),
        ]);

        setRow(local);
        setCategories(cats);
        setSuppliers(supplierRows);
        setServices(serviceRows);

        if (local?.server_uuid && statuses[local.server_uuid]) {
            setServer(statuses[local.server_uuid]);
        }

        setLoaded(true);

        return local;
    }, [localUuid]);

    const refreshFromServer = useCallback(async (serverUuid: string) => {
        if (!getSyncState().isOnline) {
            return;
        }

        try {
            const response = await fetchExpenditure(serverUuid);
            setServer(response.data);
            await mergeCachedExpenseStatuses([response.data]);
        } catch {
            // Offline or not visible to this user — the cached copy stays.
        }
    }, []);

    useFocusEffect(
        useCallback(() => {
            void loadLocal().then((local) => {
                if (local?.server_uuid) {
                    void refreshFromServer(local.server_uuid);
                }
            });
        }, [loadLocal, refreshFromServer]),
    );

    // A sync tick can sync this row (gaining a server uuid) or upload its photo.
    useEffect(
        () =>
            subscribeSyncState(() => {
                void loadLocal();
            }),
        [loadLocal],
    );

    const category = useMemo(() => categories.find((c) => c.uuid === row?.category_uuid), [categories, row]);

    async function handleAddReceipt(source: ReceiptSource) {
        if (!row || attaching) {
            return;
        }

        const captured = await captureReceipt(source);

        if (!captured) {
            return;
        }

        setAttaching(true);
        setNotice(null);

        try {
            if (getSyncState().isOnline && row.server_uuid) {
                try {
                    const response = await attachExpenditureReceipt(row.server_uuid, captured.uri, captured.source, captured.capturedAt);
                    await setExpenditureReceipt(row.local_uuid, captured, true);
                    await mergeCachedExpenseStatuses([response.data]);
                    setServer(response.data);
                    setNotice('Receipt added.');

                    if (Platform.OS === 'android') {
                        Vibration.vibrate([0, 35, 70, 35]);
                    }

                    await loadLocal();

                    return;
                } catch (error) {
                    if (!isNetworkError(error)) {
                        Alert.alert("Couldn't add the receipt", extractErrorMessage(error, 'Please try again.'));

                        return;
                    }
                    // Lost the connection mid-upload — fall through and queue it.
                }
            }

            await setExpenditureReceipt(row.local_uuid, captured, false);
            syncManager.notifyLocalWrite();
            setNotice('Saved · the photo uploads when you are online.');
            await loadLocal();
        } finally {
            setAttaching(false);
        }
    }

    if (!loaded) {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Expense' }} />
                <ActivityIndicator size="large" color={colors.accent.expense} />
            </View>
        );
    }

    if (!row) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Expense' }} />
                <EmptyState title="Expense not found" subtitle="It may have been recorded on another phone." />
            </View>
        );
    }

    const statusBadge = expenseStatusDisplay(server?.status) ?? SYNC_BADGE[row.sync_status];
    const hasLocalPhoto = !!row.receipt_local_uri;
    const hasServerPhoto = !!server?.has_receipt;
    const photoQueued = hasLocalPhoto && row.receipt_uploaded === 0;
    const canAddReceipt =
        server?.status === 'pending_evidence' || (!server && !hasLocalPhoto && row.sync_status !== 'synced');
    const supplierName =
        server?.supplier?.name ?? (row.supplier_uuid ? suppliers.find((s) => s.uuid === row.supplier_uuid)?.name : null) ?? null;
    const serviceName =
        server?.service?.name ?? (row.service_uuid ? services.find((s) => s.uuid === row.service_uuid)?.name : null) ?? 'Shared';

    const detailRows: Array<{ label: string; value: string }> = [
        { label: 'Date', value: formatShortDate(row.spent_at) },
        { label: 'Paid by', value: paymentMethodLabel(row.payment_method ?? server?.payment_method) },
    ];

    if (row.payment_ref ?? server?.payment_ref) {
        detailRows.push({ label: 'MoMo ref', value: (row.payment_ref ?? server?.payment_ref) as string });
    }

    detailRows.push({ label: 'Paid to', value: supplierName ?? 'No supplier' });

    if (server?.supplier?.status === 'pending') {
        detailRows.push({ label: 'Supplier', value: 'Awaiting approval' });
    }

    detailRows.push({ label: 'Service', value: serviceName });

    if (row.reference_no) {
        detailRows.push({ label: 'Invoice no.', value: row.reference_no });
    }

    if (server?.recorded_by) {
        detailRows.push({ label: 'Recorded by', value: server.recorded_by.name });
    }

    if (server?.first_approved_by) {
        detailRows.push({ label: 'First approval', value: server.first_approved_by.name });
    }

    if (server?.approved_by) {
        detailRows.push({ label: 'Approved by', value: server.approved_by.name });
    } else if (server?.status === 'approved' && server.auto_approved) {
        detailRows.push({ label: 'Approved by', value: 'Automatic' });
    }

    detailRows.push({ label: 'On this phone', value: SYNC_BADGE[row.sync_status].label });

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Stack.Screen options={{ title: 'Expense' }} />

            <Card accentColor={colors.accent.expense}>
                <View style={styles.heroTop}>
                    <View style={styles.glyph}>
                        <Text style={styles.glyphText}>{glyphForCategoryIcon(category?.icon, category?.name ?? '?')}</Text>
                    </View>
                    <View style={styles.heroMain}>
                        <Text style={styles.categoryName}>{category?.name ?? server?.category_name ?? 'Expense'}</Text>
                        <Badge label={statusBadge.label} tone={statusBadge.tone} />
                    </View>
                </View>
                <Text style={styles.amount}>{formatFcfa(row.amount)}</Text>
                {row.description ? <Text style={styles.description}>{row.description}</Text> : null}
                {server && server.flags.length > 0 ? (
                    <View style={styles.flags}>
                        <FlagChips flags={server.flags} />
                    </View>
                ) : null}
                {!server && row.sync_status === 'synced' && !syncState.isOnline ? (
                    <Text style={styles.muted}>Connect to see the approval status.</Text>
                ) : null}
            </Card>

            {server?.status === 'rejected' ? (
                <Card accentColor={colors.danger}>
                    <Text style={styles.reasonTitle}>Rejected{server.rejected_by ? ` by ${server.rejected_by.name}` : ''}</Text>
                    <Text style={styles.reasonBody}>{server.rejection_reason ?? 'No reason given.'}</Text>
                </Card>
            ) : null}

            {server?.status === 'void' ? (
                <Card accentColor={colors.textSecondary}>
                    <Text style={styles.reasonTitle}>Voided{server.voided_by ? ` by ${server.voided_by.name}` : ''}</Text>
                    <Text style={styles.reasonBody}>{server.void_reason ?? 'No reason given.'}</Text>
                </Card>
            ) : null}

            {row.sync_status === 'failed' && row.sync_error ? (
                <Card accentColor={colors.danger}>
                    <Text style={styles.reasonTitle}>Couldn't sync</Text>
                    <Text style={styles.reasonBody}>{row.sync_error}</Text>
                </Card>
            ) : null}

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>Receipt photo</Text>
                {hasLocalPhoto || hasServerPhoto ? (
                    <>
                        <ReceiptImage localUri={row.receipt_local_uri} remoteUrl={server?.receipt_url} />
                        {photoQueued ? <Badge label="Saved · photo uploads when online" tone="offline" /> : null}
                        {row.receipt_uploaded === 2 ? (
                            <Text style={styles.muted}>The office couldn't read this photo. Take a new one below.</Text>
                        ) : null}
                    </>
                ) : (
                    <Text style={styles.muted}>No receipt photo.</Text>
                )}

                {canAddReceipt || row.receipt_uploaded === 2 ? (
                    <Card accentColor={colors.status.offlineDot}>
                        <Text style={styles.nudgeTitle}>
                            {server?.status === 'pending_evidence' ? 'Waiting for the receipt photo' : 'Add a receipt photo'}
                        </Text>
                        <Text style={styles.nudgeBody}>
                            {server?.status === 'pending_evidence'
                                ? 'This expense moves on to approval as soon as the photo arrives.'
                                : 'A photo helps the office approve it faster.'}
                        </Text>
                        <View style={styles.receiptButtons}>
                            <Button
                                title="📷  Take photo"
                                onPress={() => void handleAddReceipt('camera')}
                                loading={attaching}
                                fullWidth={false}
                                style={styles.flexOne}
                            />
                            <Button
                                title="From gallery"
                                variant="secondary"
                                onPress={() => void handleAddReceipt('gallery')}
                                disabled={attaching}
                                fullWidth={false}
                                style={styles.flexOne}
                            />
                        </View>
                    </Card>
                ) : null}
                {notice ? <Text style={styles.notice}>{notice}</Text> : null}
            </View>

            <Card>
                <View style={styles.detailList}>
                    {detailRows.map((detail, index) => (
                        <DetailRow key={detail.label} label={detail.label} value={detail.value} last={index === detailRows.length - 1} />
                    ))}
                </View>
            </Card>

            {row.notes ? (
                <Card>
                    <Text style={styles.sectionLabel}>More details</Text>
                    <Text style={styles.description}>{row.notes}</Text>
                </Card>
            ) : null}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    flexOne: { flex: 1 },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    heroTop: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
    heroMain: { flex: 1, gap: spacing.xs },
    glyph: {
        width: 48,
        height: 48,
        borderRadius: radius.lg,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.surfaceMuted,
    },
    glyphText: { fontSize: fontSize.xl },
    categoryName: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    amount: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.accent.expense, marginTop: spacing.md },
    description: { fontSize: fontSize.md, color: colors.textPrimary, marginTop: spacing.xs },
    flags: { marginTop: spacing.md },
    muted: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
    reasonTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    reasonBody: { fontSize: fontSize.md, color: colors.textPrimary, marginTop: spacing.xs },
    section: { gap: spacing.sm },
    sectionLabel: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    nudgeTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.status.offlineFg },
    nudgeBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
    receiptButtons: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.md },
    notice: { fontSize: fontSize.sm, fontWeight: '700', color: colors.status.syncedFg },
    detailList: { gap: spacing.sm },
});
