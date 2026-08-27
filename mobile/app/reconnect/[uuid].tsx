import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Alert, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useAuth } from '../../src/auth/AuthContext';
import { fetchCustomerDetail, reconnectCustomer } from '../../src/api/customers';
import { isNetworkError, extractErrorMessage } from '../../src/api/client';
import { getSyncState, subscribeSyncState } from '../../src/sync/syncStore';
import { syncManager } from '../../src/sync/SyncManager';
import { Card } from '../../src/components/ui/Card';
import { Button } from '../../src/components/ui/Button';
import { Badge } from '../../src/components/ui/Badge';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { colors } from '../../src/theme/colors';
import { fontSize, spacing } from '../../src/theme/tokens';
import { formatFcfa } from '../../src/utils/format';
import type { CustomerDetailApi } from '../../src/types/api';

type Phase = 'loading' | 'offline' | 'error' | 'ready' | 'submitting' | 'success';

const CAN_RECONNECT_ROLES = new Set(['super', 'admin', 'manager']);

/**
 * Reconnect & Pay — a distinct flow from Record Payment, not a variant of
 * it (mobile-app-react-native.md §4). Maps directly onto the server's
 * CustomerStatusService::reconnect() via PATCH /api/v1/customers/{uuid}/
 * reconnect (App\Http\Requests\ReconnectCustomerRequest) — deliberately
 * NOT routed through the offline /sync/push payments queue:
 *
 *   - It is a status transition guarded by the customer's CURRENT
 *     server-side status (guardTransition() in CustomerStatusService),
 *     which an offline queue could easily race against a change made
 *     elsewhere (web admin, another device) in the meantime.
 *   - It has no `local_uuid` idempotency support server-side (unlike
 *     payments/expenditures — see mobile-app-react-native.md §3), so a
 *     naive retry-on-reconnect could double-charge the fine.
 *
 * So this is implemented as a straightforward online-only API call with an
 * explicit "requires connection" state when offline, per the task's
 * guidance to check whether the server logic actually supports queuing
 * this offline before forcing it into that shape (it doesn't).
 *
 * Also confirmed via App\Policies\CustomerPolicy::reconnect(): only
 * super/admin/manager can complete this action (business-rules.md §1 —
 * "Who can change status: admin, manager, super roles only"). A field
 * `agent` — the mobile app's primary user — will always get a 403 here.
 * Rather than presenting a button that's guaranteed to fail for most
 * mobile users, an unauthorized role sees the real figures read-only plus
 * a clear explanation, no confirm action.
 *
 * The reconnection fine (2026-08 owner decision, business-rules.md §6) is
 * admin-discretion opt-in for EITHER 'disconnected' or 'suspended' — never
 * automatic. The `includeFine` toggle below defaults to OFF, mirroring the
 * web admin's "Include reconnection fine" checkbox
 * (CustomerStatusActions.tsx) exactly, and is wired straight through to
 * `include_fine` on the PATCH body.
 *
 * Success confirmation is an in-screen state (phase='success' below), not a
 * native `Alert.alert` — this action is an immediate, confirmed-on-server
 * result (no offline queue, see above), so it gets the same GREEN
 * "confirmed" badge language §5 reserves exclusively for that case, in the
 * same dedicated-confirmation-view shape Record Payment/Record Expense/Log
 * Complaint already use for their own success states (banking-app UX
 * research is consistent that a dedicated confirmation view, not a native
 * OS dialog, is the expected pattern for a completed monetary action — see
 * this app's 2026-08-27 stage 2 addendum). The pre-action "Confirm
 * reconnection payment" Alert above is deliberately left as a native
 * dialog — that one is a point-of-no-return gate for an irreversible,
 * money-collecting action, a different UX role than signaling success.
 */
export default function ReconnectScreen() {
    const { uuid } = useLocalSearchParams<{ uuid: string }>();
    const router = useRouter();
    const { role } = useAuth();

    const [phase, setPhase] = useState<Phase>('loading');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [customer, setCustomer] = useState<CustomerDetailApi | null>(null);
    const [includeFine, setIncludeFine] = useState(false);
    const phaseRef = useRef<Phase>('loading');

    const load = useCallback(() => {
        if (!uuid) {
            return;
        }

        if (!getSyncState().isOnline) {
            phaseRef.current = 'offline';
            setPhase('offline');
            return;
        }

        phaseRef.current = 'loading';
        setPhase('loading');
        setErrorMessage(null);

        fetchCustomerDetail(uuid)
            .then((response) => {
                setCustomer(response.data);
                phaseRef.current = 'ready';
                setPhase('ready');
            })
            .catch((error) => {
                if (isNetworkError(error)) {
                    phaseRef.current = 'offline';
                    setPhase('offline');
                } else {
                    setErrorMessage(extractErrorMessage(error, "Couldn't load this customer."));
                    phaseRef.current = 'error';
                    setPhase('error');
                }
            });
    }, [uuid]);

    // Loads on focus, then stays subscribed to sync state while focused so
    // that if connectivity comes back while the offline state is showing,
    // the screen retries on its own rather than making the agent tap Retry
    // — consistent with the calm "offline is normal" principle (§5). Only
    // re-loads on an offline->online transition, not on every sync tick.
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

    async function handleConfirm() {
        if (!customer || !uuid) {
            return;
        }

        const arrears = Number(customer.manuscript?.total_arrears ?? 0);
        const fine = includeFine ? Number(customer.reconnection_fine ?? 0) : 0;
        const total = arrears + fine;

        Alert.alert(
            'Confirm reconnection payment',
            `Collect ${formatFcfa(total)} from ${customer.name} and reconnect their service?`,
            [
                { text: 'Cancel', style: 'cancel' },
                {
                    text: 'Confirm',
                    onPress: () => void submit(arrears),
                },
            ],
        );
    }

    async function submit(arrears: number) {
        if (!uuid || !customer) {
            return;
        }

        phaseRef.current = 'submitting';
        setPhase('submitting');

        try {
            await reconnectCustomer(uuid, {
                include_fine: includeFine,
                arrears_payment: arrears > 0 ? arrears.toFixed(2) : undefined,
            });

            // Best-effort — pulls the updated status/manuscript into the
            // local cache sooner than the next natural sync trigger, so
            // Customer Detail (which reads local-only) reflects it quickly.
            void syncManager.syncNow('manual');

            phaseRef.current = 'success';
            setPhase('success');
        } catch (error) {
            phaseRef.current = 'ready';
            setPhase('ready');
            Alert.alert('Could not reconnect', extractErrorMessage(error, 'Something went wrong.'));
        }
    }

    if (phase === 'loading') {
        return (
            <View style={styles.center}>
                <Stack.Screen options={{ title: 'Reconnect & Pay' }} />
                <ActivityIndicator size="large" color={colors.accent.customers} />
            </View>
        );
    }

    if (phase === 'offline') {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Reconnect & Pay' }} />
                <EmptyState
                    title="Requires an internet connection"
                    subtitle="Reconnecting a customer changes their billing status on the server and can't be queued offline. Connect and this screen will pick up automatically."
                    actionLabel="Try again"
                    onAction={load}
                />
            </View>
        );
    }

    if (phase === 'error' || !customer) {
        return (
            <View style={styles.flex}>
                <Stack.Screen options={{ title: 'Reconnect & Pay' }} />
                <EmptyState title="Couldn't load this customer" subtitle={errorMessage ?? undefined} actionLabel="Try again" onAction={load} />
            </View>
        );
    }

    if (phase === 'success') {
        const collected = Number(customer.manuscript?.total_arrears ?? 0) + (includeFine ? Number(customer.reconnection_fine ?? 0) : 0);

        return (
            <View style={styles.confirmFlex}>
                <Stack.Screen options={{ title: 'Reconnect & Pay' }} />
                <Badge label="Reconnected" tone="synced" />
                <Text style={styles.confirmTitle}>{customer.name}</Text>
                <Text style={styles.confirmBody}>{formatFcfa(collected)} collected · service restored</Text>
                <Text style={styles.confirmHint}>Confirmed on the server just now.</Text>
                <Button title="Done" size="large" onPress={() => router.back()} style={styles.confirmButton} />
            </View>
        );
    }

    const arrears = Number(customer.manuscript?.total_arrears ?? 0);
    const fine = Number(customer.reconnection_fine ?? 0);
    const total = arrears + (includeFine ? fine : 0);
    const authorized = role !== null && CAN_RECONNECT_ROLES.has(role);
    const submitting = phase === 'submitting';

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Stack.Screen options={{ title: 'Reconnect & Pay' }} />

            <Card>
                <Text style={styles.customerName}>{customer.name}</Text>
                <Text style={styles.customerStatus}>
                    Currently {customer.status === 'disconnected' ? 'disconnected' : 'suspended'}
                </Text>
            </Card>

            <Card>
                <View style={styles.lineRow}>
                    <Text style={styles.lineLabel}>Outstanding arrears</Text>
                    <Text style={styles.lineValue}>{formatFcfa(arrears)}</Text>
                </View>
                <View style={styles.lineRow}>
                    <View style={styles.fineToggleLabel}>
                        <Text style={styles.lineLabel}>Include reconnection fine</Text>
                        <Text style={styles.fineToggleHint}>Optional — {formatFcfa(fine)}, admin discretion</Text>
                    </View>
                    <Switch value={includeFine} onValueChange={setIncludeFine} />
                </View>
                <View style={[styles.lineRow, styles.totalRow]}>
                    <Text style={styles.totalLabel}>Total to collect</Text>
                    <Text style={styles.totalValue}>{formatFcfa(total)}</Text>
                </View>
                <Text style={styles.readOnlyHint}>Arrears figure is computed from the server; the fine is your choice to include.</Text>
            </Card>

            {authorized ? (
                <Button
                    title={submitting ? 'Reconnecting…' : 'Confirm Reconnection Payment'}
                    size="large"
                    loading={submitting}
                    disabled={submitting}
                    onPress={handleConfirm}
                />
            ) : (
                <Card accentColor={colors.status.offlineDot}>
                    <Text style={styles.notAuthorizedTitle}>Manager or admin approval required</Text>
                    <Text style={styles.notAuthorizedBody}>
                        Reconnecting a customer's service is restricted to office staff (manager, admin, or super).
                        Contact your office with these figures to complete the reconnection.
                    </Text>
                </Card>
            )}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    confirmFlex: {
        flex: 1,
        backgroundColor: colors.background,
        alignItems: 'center',
        justifyContent: 'center',
        padding: spacing.xl,
        gap: spacing.sm,
    },
    confirmTitle: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary, marginTop: spacing.md },
    confirmBody: { fontSize: fontSize.lg, fontWeight: '600', color: colors.textPrimary, textAlign: 'center' },
    confirmHint: { fontSize: fontSize.sm, color: colors.textSecondary, textAlign: 'center', marginBottom: spacing.lg },
    confirmButton: { width: '100%' },
    customerName: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    customerStatus: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs, textTransform: 'capitalize' },
    lineRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: spacing.xs },
    lineLabel: { fontSize: fontSize.md, color: colors.textSecondary },
    lineValue: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    fineToggleLabel: { flex: 1, marginRight: spacing.sm },
    fineToggleHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: 2 },
    totalRow: { borderTopWidth: 1, borderTopColor: colors.border, marginTop: spacing.sm, paddingTop: spacing.md },
    totalLabel: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    totalValue: { fontSize: fontSize.xl, fontWeight: '800', color: colors.accent.payment },
    readOnlyHint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.sm },
    notAuthorizedTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    notAuthorizedBody: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: spacing.xs },
});
