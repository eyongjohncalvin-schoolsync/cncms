import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Platform, RefreshControl, StyleSheet, Text, Vibration, View } from 'react-native';
import { Stack, useFocusEffect } from 'expo-router';
import { useAuth } from '../src/auth/AuthContext';
import { Card } from '../src/components/ui/Card';
import { Badge } from '../src/components/ui/Badge';
import { Button } from '../src/components/ui/Button';
import { EmptyState } from '../src/components/ui/EmptyState';
import { FlagChips } from '../src/components/expenses/FlagChips';
import { ReasonSheet } from '../src/components/expenses/ReasonSheet';
import { ReceiptImage } from '../src/components/expenses/ReceiptImage';
import { approveExpenditure, fetchApprovals, rejectExpenditure } from '../src/api/expenditures';
import { extractErrorMessage, isNetworkError } from '../src/api/client';
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { setApprovalsCount } from '../src/sync/approvalsStore';
import { expenseStatusDisplay, paymentMethodLabel, REJECT_QUICK_REASONS } from '../src/utils/expenseControls';
import { formatFcfa, formatShortDate } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing } from '../src/theme/tokens';
import type { ExpenditureApi } from '../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready';

function successBuzz(): void {
    if (Platform.OS === 'android') {
        Vibration.vibrate(30);
    }
}

/**
 * Approvals inbox — expenses waiting for THIS manager (GET
 * /resources/approvals), for users holding expenditures.approve or
 * expenditures.approve_large. Each card carries what a manager needs to
 * decide on the spot: amount, category, who recorded it and when, the
 * server's control flags (amber chips) and the receipt photo (tap for full
 * screen). Approve is one tap; Reject asks for a reason (quick chips +
 * text) because the worker sees it.
 *
 * ONLINE-ONLY, same pattern as app/receipt/[uuid].tsx: decisions are live
 * server actions, never queued, and the list re-loads by itself when
 * connectivity returns. Server rules (e.g. "You can't approve an expense
 * you recorded yourself.") come back as 422 messages and are shown as-is.
 */
export default function ApprovalsScreen() {
    const { can, status: authStatus } = useAuth();
    const authorized = can('expenditures.approve') || can('expenditures.approve_large');

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [items, setItems] = useState<ExpenditureApi[]>([]);
    const [refreshing, setRefreshing] = useState(false);
    const [busyUuid, setBusyUuid] = useState<string | null>(null);
    const [rejecting, setRejecting] = useState<ExpenditureApi | null>(null);
    const [rejectSubmitting, setRejectSubmitting] = useState(false);
    const [rejectError, setRejectError] = useState<string | null>(null);
    const [lastDone, setLastDone] = useState<string | null>(null);
    const phaseRef = useRef<Phase>('loading');

    const updatePhase = (next: Phase) => {
        phaseRef.current = next;
        setPhase(next);
    };

    const load = useCallback(
        async (mode: 'initial' | 'refresh' = 'initial') => {
            if (!authorized) {
                return;
            }

            if (!getSyncState().isOnline) {
                updatePhase('offline');
                setRefreshing(false);
                return;
            }

            if (mode === 'refresh') {
                setRefreshing(true);
            } else if (phaseRef.current !== 'ready') {
                updatePhase('loading');
            }

            try {
                const response = await fetchApprovals();
                setItems(response.data);
                setApprovalsCount(response.data.length);
                setErrorMessage(null);
                updatePhase('ready');
            } catch (error) {
                if (isNetworkError(error)) {
                    updatePhase('offline');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load approvals."));
                    updatePhase('error');
                }
            } finally {
                setRefreshing(false);
            }
        },
        [authorized],
    );

    useFocusEffect(
        useCallback(() => {
            void load();

            return subscribeSyncState(() => {
                if (phaseRef.current === 'offline' && getSyncState().isOnline) {
                    void load();
                }
            });
        }, [load]),
    );

    function removeItem(uuid: string, message: string) {
        setItems((previous) => {
            const next = previous.filter((item) => item.uuid !== uuid);
            setApprovalsCount(next.length);

            return next;
        });
        setLastDone(message);
        successBuzz();
    }

    async function handleApprove(item: ExpenditureApi) {
        if (busyUuid) {
            return;
        }

        setBusyUuid(item.uuid);

        try {
            const response = await approveExpenditure(item.uuid);
            const nowStatus = response.data.status;
            removeItem(
                item.uuid,
                nowStatus === 'pending_second_approval'
                    ? `${formatFcfa(Number(item.amount))} approved — now waiting for the owner.`
                    : `${formatFcfa(Number(item.amount))} approved.`,
            );
        } catch (error) {
            Alert.alert(
                isNetworkError(error) ? 'No connection' : "Couldn't approve",
                extractErrorMessage(error, 'Please try again.'),
            );
        } finally {
            setBusyUuid(null);
        }
    }

    async function handleReject(reason: string) {
        if (!rejecting) {
            return;
        }

        setRejectSubmitting(true);
        setRejectError(null);

        try {
            await rejectExpenditure(rejecting.uuid, reason);
            removeItem(rejecting.uuid, `${formatFcfa(Number(rejecting.amount))} rejected.`);
            setRejecting(null);
        } catch (error) {
            setRejectError(extractErrorMessage(error, "Couldn't reject — please try again."));
        } finally {
            setRejectSubmitting(false);
        }
    }

    if (authStatus === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Approvals' }} />
                <ActivityIndicator size="large" color={colors.accent.expense} />
            </View>
        );
    }

    if (!authorized) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Approvals' }} />
                <View style={styles.content}>
                    <Card accentColor={colors.status.offlineDot}>
                        <Text style={styles.cardTitle}>Approvals aren't part of your role</Text>
                        <Text style={styles.muted}>Contact your office if you believe this is a mistake.</Text>
                    </Card>
                </View>
            </View>
        );
    }

    if (phase === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Approvals' }} />
                <ActivityIndicator size="large" color={colors.accent.expense} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Approvals' }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="Approvals are decided live on the server. Connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={() => void load()}
                />
            </View>
        );
    }

    if (phase === 'error') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Approvals' }} />
                <EmptyState
                    title="Couldn't load approvals"
                    subtitle={errorMessage ?? undefined}
                    actionLabel="Try again"
                    onAction={() => void load()}
                />
            </View>
        );
    }

    return (
        <View style={styles.flex}>
            <Stack.Screen options={{ title: 'Approvals' }} />
            <FlatList
                data={items}
                keyExtractor={(item) => item.uuid}
                contentContainerStyle={styles.content}
                refreshControl={
                    <RefreshControl refreshing={refreshing} onRefresh={() => void load('refresh')} tintColor={colors.accent.expense} />
                }
                ListHeaderComponent={
                    lastDone ? (
                        <View style={styles.doneBanner}>
                            <Text style={styles.doneText}>✓ {lastDone}</Text>
                        </View>
                    ) : null
                }
                ListEmptyComponent={
                    <EmptyState title="All caught up" subtitle="No expenses are waiting for your approval. Pull down to check again." />
                }
                renderItem={({ item }) => {
                    const status = expenseStatusDisplay(item.status);
                    const busy = busyUuid === item.uuid;
                    const meta = [
                        item.recorded_by?.name ?? 'Unknown',
                        item.spent_at ? formatShortDate(item.spent_at) : null,
                        paymentMethodLabel(item.payment_method),
                    ]
                        .filter(Boolean)
                        .join(' · ');

                    return (
                        <Card style={styles.card}>
                            <View style={styles.cardTop}>
                                <View style={styles.cardMain}>
                                    <Text style={styles.amount}>{formatFcfa(Number(item.amount))}</Text>
                                    <Text style={styles.cardTitle}>{item.category_name ?? 'Expense'}</Text>
                                    <Text style={styles.muted}>{meta}</Text>
                                    {item.supplier ? (
                                        <Text style={styles.muted}>
                                            Paid to {item.supplier.name}
                                            {item.supplier.status === 'pending' ? ' (awaiting approval)' : ''}
                                        </Text>
                                    ) : null}
                                    {item.first_approved_by ? (
                                        <Text style={styles.muted}>First approved by {item.first_approved_by.name}</Text>
                                    ) : null}
                                </View>
                                {status ? <Badge label={status.label} tone={status.tone} /> : null}
                            </View>

                            {item.description ? <Text style={styles.description}>{item.description}</Text> : null}

                            <FlagChips flags={item.flags} />

                            {item.has_receipt ? (
                                <ReceiptImage remoteUrl={item.receipt_url} style={styles.thumb} />
                            ) : (
                                <Text style={styles.noReceipt}>No receipt photo</Text>
                            )}

                            <View style={styles.actions}>
                                <Button
                                    title="Reject"
                                    variant="dangerOutline"
                                    onPress={() => {
                                        setRejectError(null);
                                        setRejecting(item);
                                    }}
                                    disabled={busy}
                                    fullWidth={false}
                                    style={styles.action}
                                />
                                <Button
                                    title="Approve"
                                    size="large"
                                    onPress={() => void handleApprove(item)}
                                    loading={busy}
                                    disabled={busyUuid !== null && !busy}
                                    fullWidth={false}
                                    style={styles.actionPrimary}
                                />
                            </View>
                        </Card>
                    );
                }}
            />

            <ReasonSheet
                visible={rejecting !== null}
                title={rejecting ? `Reject ${formatFcfa(Number(rejecting.amount))}?` : 'Reject'}
                subtitle={rejecting ? `${rejecting.recorded_by?.name ?? 'The worker'} will see your reason.` : undefined}
                quickReasons={REJECT_QUICK_REASONS}
                confirmLabel="Reject"
                submitting={rejectSubmitting}
                error={rejectError}
                onCancel={() => setRejecting(null)}
                onConfirm={(reason) => void handleReject(reason)}
            />
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl, flexGrow: 1 },
    card: { gap: spacing.md },
    cardTop: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: spacing.sm },
    cardMain: { flex: 1, gap: 2 },
    amount: { fontSize: fontSize.xxl, fontWeight: '800', color: colors.accent.expense },
    cardTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    muted: { fontSize: fontSize.sm, color: colors.textSecondary },
    description: { fontSize: fontSize.md, color: colors.textPrimary },
    thumb: { height: 140 },
    noReceipt: { fontSize: fontSize.sm, color: colors.status.offlineFg, fontWeight: '600' },
    actions: { flexDirection: 'row', gap: spacing.sm, alignItems: 'center' },
    action: { flex: 1, height: 56 },
    actionPrimary: { flex: 2 },
    doneBanner: {
        borderRadius: radius.md,
        backgroundColor: colors.status.syncedBg,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        marginBottom: spacing.xs,
    },
    doneText: { fontSize: fontSize.sm, fontWeight: '700', color: colors.status.syncedFg },
});
